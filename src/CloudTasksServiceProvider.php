<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use \Grpc\ChannelCredentials;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use function Safe\file_get_contents;
use function Safe\json_decode;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(QueueManager $queue, Router $router): void
    {
        $this->authorization();

        $this->registerClient();
        $this->registerConnector($queue);
        $this->registerViews();
        $this->registerAssets();
        $this->registerMigrations();
        $this->registerRoutes($router);
        $this->registerMonitoring();
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
            return app()->environment('local', 'testing') ||
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

    private function registerClient(): void
    {
        $this->app->singleton(CloudTasksClient::class, function () {
            return new CloudTasksClient();
        });

        $this->app->bind('open-id-verificator', OpenIdVerificatorConcrete::class);
        $this->app->bind('cloud-tasks-api', CloudTasksApiConcrete::class);
    }

    private function registerConnector(QueueManager $queue): void
    {
        $queue->addConnector('cloudtasks', function () {
            return new CloudTasksConnector;
        });
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../views', 'cloud-tasks');
    }

    private function registerAssets(): void
    {
        $this->publishes([
            __DIR__ . '/../dashboard/dist' => public_path('vendor/cloud-tasks'),
        ], ['cloud-tasks-assets']);
    }

    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom([
            __DIR__ . '/../migrations',
        ]);
    }

    private function registerRoutes(Router $router): void
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

    private function registerMonitoring(): void
    {
        app('events')->listen(JobProcessing::class, function (JobProcessing $event) {
            if ($event->job instanceof CloudTasksJob) {
                MonitoringService::make()->markAsRunning($event->job->uuid());
            }
        });

        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            if ($event->job instanceof CloudTasksJob) {
                MonitoringService::make()->markAsSuccessful($event->job->uuid());
            }
        });

        app('events')->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            MonitoringService::make()->markAsError($event);
        });

        app('events')->listen(JobFailed::class, function ($event) {
            MonitoringService::make()->markAsFailed(
                $event
            );

            $config = $event->job->cloudTasksQueue->config;

            app('queue.failer')->log(
                $config['connection'], $event->job->getQueue() ?: $config['queue'],
                $event->job->getRawBody(), $event->exception
            );
        });
    }
}
