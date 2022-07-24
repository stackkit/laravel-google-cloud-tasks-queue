<?php

namespace Tests;

use Closure;
use Firebase\JWT\JWT;
use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\Queue;
use Google\Cloud\Tasks\V2\RetryConfig;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Support\Facades\Event;
use Mockery;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskCreated;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;

class TestCase extends \Orchestra\Testbench\TestCase
{
    use DatabaseTransactions;

    /**
     * @var \Mockery\Mock|CloudTasksClient $client
     */
    public $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withFactories(__DIR__ . '/../factories');

        $this->defaultHeaders['Authorization'] = 'Bearer ' . encrypt(time() + 10);
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
            'handler' => env('CLOUD_TASKS_HANDLER', 'http://docker.for.mac.localhost:8080'),
            'service_account_email' => 'info@stackkit.io',
        ]);
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'testbench');

        $disableDashboardPrefix = 'when_dashboard_is_disabled';

        if (substr($this->getName(), 0, strlen($disableDashboardPrefix)) === $disableDashboardPrefix) {
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
            $payload = $event->task->getHttpRequest()->getBody();
            $payloadAsArray = json_decode($payload, true);
            $task = $event->task;

            request()->headers->set('X-Cloudtasks-Taskname', $task->getName());
        });

        dispatch($job);

        return new class($payload, $payloadAsArray, $task) {
            public string $payload;
            public array $payloadAsArray;
            public Task $task;

            public function __construct(string $payload, array $payloadAsArray, Task $task)
            {
                $this->payload = $payload;
                $this->payloadAsArray = $payloadAsArray;
                $this->task = $task;
            }

            public function run(): void
            {
                $taskRetryCount = request()->header('X-CloudTasks-TaskRetryCount', -1);
                request()->headers->set('X-CloudTasks-TaskRetryCount', $taskRetryCount + 1);

                rescue(function (): void {
                    app(TaskHandler::class)->handle($this->payload);
                });

                $this->payload = $this->incrementAttempts($this->payload);
            }

            public function runWithoutExceptionHandler(): void
            {
                $taskRetryCount = request()->header('X-CloudTasks-TaskRetryCount', -1);
                request()->headers->set('X-CloudTasks-TaskRetryCount', $taskRetryCount + 1);

                app(TaskHandler::class)->handle($this->payload);

                $this->payload = $this->incrementAttempts($this->payload);
            }

            private function incrementAttempts(string $payload): string
            {
                $decoded = \Safe\json_decode($payload, true);

                $decoded['internal']['attempts'] ??= 0;
                $decoded['internal']['attempts']++;

                return json_encode($decoded);
            }
        };
    }

    public function runFromPayload(array $payload): void
    {
        rescue(function () use ($payload) {
            app(TaskHandler::class)->handle($payload);
        });
    }

    public function dispatchAndRun($job): void
    {
        $this->runFromPayload($this->dispatch($job));
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
            'aud' => 'http://docker.for.mac.localhost:8080',
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
}
