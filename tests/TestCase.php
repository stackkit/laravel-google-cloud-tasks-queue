<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Mockery;

class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * @var \Mockery\Mock $client
     */
    public $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forwardToEmulatorClient();
    }

    /**
     * Forward the Tasks Client to the local emulator.
     *
     * @return void
     */
    private function forwardToEmulatorClient(): void
    {
        $this->client = $this->instance(
            CloudTasksClient::class,
            Mockery::mock(
                new CloudTasksClient([
                    'apiEndpoint' => 'localhost:8123',
                    'transport' => 'grpc',
                    'transportConfig' => [
                        'grpc' => [
                            'stubOpts' => [
                                'credentials' => \Grpc\ChannelCredentials::createInsecure()
                            ]
                        ]
                    ]
                ])
            )->makePartial()
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

        $app['config']->set('cache.default', 'file');
        $app['config']->set('queue.default', 'my-cloudtasks-connection');
        $app['config']->set('queue.connections.my-cloudtasks-connection', [
            'driver' => 'cloudtasks',
            'queue' => 'barbequeue',
            'project' => 'my-test-project',
            'location' => 'europe-west6',
            'handler' => env('CLOUD_TASKS_HANDLER', 'http://docker.for.mac.localhost:8080/handle-task'),
            'service_account_email' => 'info@stackkit.io',
        ]);
    }

    protected function setConfigValue($key, $value)
    {
        $this->app['config']->set('queue.connections.my-cloudtasks-connection.' . $key, $value);
    }

    protected function sleep(int $ms)
    {
        usleep($ms * 1000);
    }

    public function clearTables()
    {
        DB::table('failed_jobs')->truncate();
        DB::table('stackkit_cloud_tasks')->truncate();
    }

    protected function logFilePath(): string
    {
        return __DIR__ . '/laravel/storage/logs/laravel.log';
    }

    protected function clearLaravelStorageFile()
    {
        if (!file_exists($this->logFilePath())) {
            touch($this->logFilePath());
            return;
        }

        file_put_contents($this->logFilePath(), '');
    }

    protected function assertLogEmpty()
    {
        $this->assertEquals('', file_get_contents($this->logFilePath()));
    }

    protected function assertLogContains(string $contains)
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            if (file_exists($this->logFilePath())) {
                $contents = file_get_contents($this->logFilePath());

                if (!empty($contents)) {
                    $this->assertStringContainsString($contains, $contents);
                    return;
                }
            }

            if ($attempts >= 50) {
                break;
            }

            usleep(0.1 * 1000000);
        }

        $this->fail('The log file does not contain: ' . $contains);
    }

    protected function getLogContents()
    {
        return file_exists($this->logFilePath()) ? file_get_contents($this->logFilePath()) : '';
    }
}
