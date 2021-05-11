<?php

namespace Tests;

class TestCase extends \Orchestra\Testbench\TestCase
{
    public static $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // There is probably a more sane way to do this
        if (!static::$migrated) {
            if (file_exists(database_path('database.sqlite'))) {
                unlink(database_path('database.sqlite'));
            }

            touch(database_path('database.sqlite'));

            foreach(glob(database_path('migrations/*.php')) as $file) {
                unlink($file);
            }

            $this->artisan('queue:failed-table');
            $this->artisan('migrate');

            static::$migrated = true;
        }
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
        $app['config']->set('queue.default', 'cloudtasks');
        $app['config']->set('queue.connections.cloudtasks', [
            'driver' => 'cloudtasks',
            'queue' => 'test-queue',
            'project' => 'test-project',
            'location' => 'europe-west6',
            'handler' => 'https://localhost/my-handler',
            'service_account_email' => 'info@stackkit.io',
        ]);
    }

    protected function setConfigValue($key, $value)
    {
        $this->app['config']->set('queue.connections.cloudtasks.' . $key, $value);
    }
}
