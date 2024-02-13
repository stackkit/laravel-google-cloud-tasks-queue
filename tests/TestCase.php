<?php

namespace Tests;

use Closure;
use Firebase\JWT\JWT;
use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleasedAfterException as PackageJobReleasedAfterException;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;

class TestCase extends \Orchestra\Testbench\TestCase
{
    use DatabaseTransactions;

    /**
     * @var \Mockery\Mock|CloudTasksClient $client
     */
    public $client;

    public string $releasedJobPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withFactories(__DIR__ . '/../factories');

        $this->defaultHeaders['Authorization'] = 'Bearer ' . encrypt(time() + 10);

        Event::listen(
            $this->getJobReleasedAfterExceptionEvent(),
            function ($event) {
                $this->releasedJobPayload = $event->job->getRawBody();
            }
        );
    }

    /**
     * Get package providers.  At a minimum this is the package being tested, but also
     * would include packages upon which our package depends, e.g. Cartalyst/Sentry
     * In a normal app environment these would be added to the 'providers' array in
     * the config/app.php file.
     *
     * @param  \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            \Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksServiceProvider::class,
        ];
    }

    /**
     * Define database migrations.
     *
     * @return void
     */
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/orchestra/testbench-core/laravel/migrations');
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        foreach (glob(storage_path('framework/cache/data/*/*/*')) as $file) {
            unlink($file);
        }

        $app['config']->set('database.default', 'testbench');
        $port = env('DB_DRIVER') === 'mysql' ? 3307 : 5432;
        $app['config']->set('database.connections.testbench', [
            'driver'   => env('DB_DRIVER', 'mysql'),
            'host' => '127.0.0.1',
            'port' => $port,
            'database' => 'cloudtasks',
            'username' => 'cloudtasks',
            'password' => 'cloudtasks',
            'prefix'   => '',
        ]);

        $app['config']->set('cache.default', 'file');
        $app['config']->set('queue.default', 'my-cloudtasks-connection');
        $app['config']->set('queue.connections.my-cloudtasks-connection', [
            'driver' => 'cloudtasks',
            'queue' => 'barbequeue',
            'project' => 'my-test-project',
            'location' => 'europe-west6',
            'handler' => env('CLOUD_TASKS_HANDLER', 'https://docker.for.mac.localhost:8080'),
            'service_account_email' => 'info@stackkit.io',
            'signed_audience' => true,
        ]);
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'testbench');

        $disableDashboardPrefix = 'when_dashboard_is_disabled';

        $testName = method_exists($this, 'name') ? $this->name() : $this->getName();
        if (substr($testName, 0, strlen($disableDashboardPrefix)) === $disableDashboardPrefix) {
            $app['config']->set('cloud-tasks.dashboard.enabled', false);
        } else {
            $app['config']->set('cloud-tasks.dashboard.enabled', true);
        }
    }

    protected function setConfigValue($key, $value)
    {
        $this->app['config']->set('queue.connections.my-cloudtasks-connection.' . $key, $value);
    }

    public function dispatch($job)
    {
        $payload = null;
        $payloadAsArray = [];
        $task = null;

        Event::listen(TaskCreated::class, function (TaskCreated $event) use (&$payload, &$payloadAsArray, &$task) {
            $request = $event->task->getHttpRequest() ?? $event->task->getAppEngineHttpRequest();
            $payload = $request->getBody();
            $payloadAsArray = json_decode($payload, true);
            $task = $event->task;

            [,,,,,,,$taskName] = explode('/', $task->getName());

            if ($task->hasHttpRequest()) {
                request()->headers->set('X-Cloudtasks-Taskname', $taskName);
            }

            if ($task->hasAppEngineHttpRequest()) {
                request()->headers->set('X-AppEngine-TaskName', $taskName);
            }
        });

        dispatch($job);

        return new class($payload, $task, $this) {
            public string $payload;
            public Task $task;
            public TestCase $testCase;

            public function __construct(string $payload, Task $task, TestCase $testCase)
            {
                $this->payload = $payload;
                $this->task = $task;
                $this->testCase = $testCase;
            }

            public function run(): void
            {
                rescue(function (): void {
                    app(TaskHandler::class)->handle($this->payload);
                });
            }

            public function runWithoutExceptionHandler(): void
            {
                app(TaskHandler::class)->handle($this->payload);
            }

            public function runAndGetReleasedJob(): self
            {
                rescue(function (): void {
                    app(TaskHandler::class)->handle($this->payload);
                });

                return new self(
                    $this->testCase->releasedJobPayload,
                    $this->task,
                    $this->testCase
                );
            }

            public function payloadAsArray(string $key = '')
            {
                $decoded = json_decode($this->payload, true);

                return data_get($decoded, $key ?: null);
            }
        };
    }

    public function runFromPayload(string $payload): void
    {
        rescue(function () use ($payload) {
            app(TaskHandler::class)->handle($payload);
        });
    }

    public function assertTaskDeleted(string $taskId): void
    {
        try {
            $this->client->getTask($taskId);

            $this->fail('Getting the task should throw an exception but it did not.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('The task no longer exists', $e->getMessage());
        }
    }

    public function assertTaskExists(string $taskId): void
    {
        try {
            $task = $this->client->getTask($taskId);

            $this->assertInstanceOf(Task::class, $task);
        } catch (ApiException $e) {
            $this->fail('Task [' . $taskId . '] should exist but it does not (or something else went wrong).');
        }
    }

    protected function addIdTokenToHeader(?Closure $closure = null): void
    {
        $base = [
            'iss' => 'https://accounts.google.com',
            'aud' => 'https://docker.for.mac.localhost:8080',
            'exp' => time() + 10,
        ];

        if ($closure) {
            $base = $closure($base);
        }

        $privateKey = file_get_contents(__DIR__ . '/../tests/Support/self-signed-private-key.txt');

        $token = JWT::encode($base, $privateKey, 'RS256', 'abc123');

        request()->headers->set('Authorization', 'Bearer ' . $token);
    }

    protected function assertDatabaseCount($table, int $count, $connection = null)
    {
        $this->assertEquals($count, DB::connection($connection)->table($table)->count());
    }

    public function getJobReleasedAfterExceptionEvent(): string
    {
        // The JobReleasedAfterException event is not available in Laravel versions
        // below 9.x so instead for those versions we throw our own event which
        // is identical to the Laravel one.
        return version_compare(app()->version(), '9.0.0', '<')
            ? PackageJobReleasedAfterException::class
            : JobReleasedAfterException::class;
    }

    public function withTaskType(string $taskType): void
    {
        switch ($taskType) {
            case 'appengine':
                $this->setConfigValue('handler', null);
                $this->setConfigValue('service_account_email', null);
                $this->setConfigValue('signed_audience', null);

                $this->setConfigValue('app_engine', true);
                $this->setConfigValue('app_engine_service', 'api');
                break;
            case 'http':
                $this->setConfigValue('app_engine', false);
                $this->setConfigValue('app_engine_service', null);

                $this->setConfigValue('handler', 'https://docker.for.mac.localhost:8080');
                $this->setConfigValue('service_account_email', 'info@stackkit.io');
                $this->setConfigValue('signed_audience', true);
                break;
        }
    }
}
