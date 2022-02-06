<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(QueueManager $queue, Router $router)
    {
        $this->authorization();

        $this->registerClient();
        $this->registerConnector($queue);
        $this->registerViews();
        $this->registerAssets();
        $this->registerMigrations();
        $this->registerRoutes($router);
    }

    /**
     * Configure the Cloud Tasks authorization services.
     *
     * @return void
     */
    protected function authorization()
    {
        $this->gate();

        CloudTasks::auth(function ($request) {
            return app()->environment('local') ||
                Gate::check('viewCloudTasks', [$request->user()]);
        });
    }

    /**
     * Register the Cloud Tasks gate.
     *
     * This gate determines who can access Cloud Tasks in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        Gate::define('viewCloudTasks', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }

    private function registerClient()
    {
        $this->app->singleton(CloudTasksClient::class, function () {
            return new CloudTasksClient();
        });
    }

    private function registerConnector(QueueManager $queue)
    {
        $queue->addConnector('cloudtasks', function () {
            return new CloudTasksConnector;
        });
    }

    private function registerViews()
    {
        $this->loadViewsFrom(__DIR__ . '/../views', 'cloud-tasks');
    }

    private function registerAssets()
    {
        $this->publishes([
            __DIR__ . '/../dashboard/dist' => public_path('vendor/cloud-tasks'),
        ], ['cloud-tasks-assets']);
    }

    private function registerMigrations()
    {
        $this->loadMigrationsFrom([
            __DIR__ . '/../migrations',
        ]);
    }

    private function registerRoutes(Router $router)
    {
        $router->post('handle-task', [TaskHandler::class, 'handle']);

        $router->middleware(Authenticate::class)->group(function () use ($router) {
            $router->get('cloud-tasks/{view?}', function () {
                return view('cloud-tasks::layout', [
                    'manifest' => json_decode(file_get_contents(public_path('vendor/cloud-tasks/manifest.json')), true),
                    'isDownForMaintenance' => app()->isDownForMaintenance(),
                    'cloudTasksScriptVariables' => [
                        'path' => 'cloud-tasks',
                    ],
                ]);
            })->where(
                'view',
                '(.+)'
            )->name(
                'cloud-tasks.index'
            );

            $router->get('cloud-tasks-api/dashboard', [CloudTasksApiController::class, 'dashboard']);
            $router->get('cloud-tasks-api/tasks', [CloudTasksApiController::class, 'tasks']);
            $router->get('cloud-tasks-api/task/{uuid}', [CloudTasksApiController::class, 'task']);
        });
    }
}
