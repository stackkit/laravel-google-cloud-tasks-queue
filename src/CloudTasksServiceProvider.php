<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use function Safe\file_get_contents;
use function Safe\json_decode;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(QueueManager $queue, Router $router): void
    {
        $this->registerClient();
        $this->registerConnector($queue);
        $this->registerConfig();
        $this->registerViews();
        $this->registerAssets();
        $this->registerMigrations();
        $this->registerRoutes($router);
        $this->registerMonitoring();
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

    private function registerConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/cloud-tasks.php' => config_path('cloud-tasks.php'),
        ], ['cloud-tasks']);

        $this->mergeConfigFrom(__DIR__ . '/../config/cloud-tasks.php', 'cloud-tasks');
    }

    private function registerViews(): void
    {
        if (CloudTasks::monitorDisabled()) {
            // Larastan needs this view registered to check the service provider correctly.
            // return;
        }

        $this->loadViewsFrom(__DIR__ . '/../views', 'cloud-tasks');
    }

    private function registerAssets(): void
    {
        if (CloudTasks::monitorDisabled()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../dashboard/dist' => public_path('vendor/cloud-tasks'),
        ], ['cloud-tasks']);
    }

    private function registerMigrations(): void
    {
        if (CloudTasks::monitorDisabled()) {
            return;
        }

        $this->loadMigrationsFrom([
            __DIR__ . '/../migrations',
        ]);
    }

    private function registerRoutes(Router $router): void
    {
        $router->post('handle-task', [TaskHandler::class, 'handle'])->name('cloud-tasks.handle-task');

        if (config('cloud-tasks.monitor.enabled') === false) {
            return;
        }

        $router->post('cloud-tasks-api/login', [CloudTasksApiController::class, 'login'])->name('cloud-tasks.api.login');
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

            $router->get('cloud-tasks-api/dashboard', [CloudTasksApiController::class, 'dashboard'])->name('cloud-tasks.api.dashboard');
            $router->get('cloud-tasks-api/tasks', [CloudTasksApiController::class, 'tasks'])->name('cloud-tasks.api.tasks');
            $router->get('cloud-tasks-api/task/{uuid}', [CloudTasksApiController::class, 'task'])->name('cloud-tasks.api.task');
        });
    }

    private function registerMonitoring(): void
    {
        app('events')->listen(TaskCreated::class, function (TaskCreated $event) {
            if (CloudTasks::monitorDisabled()) {
                return;
            }

            MonitoringService::make()->addToMonitor($event->queue, $event->task);
        });

        app('events')->listen(JobFailed::class, function (JobFailed $event) {
            if (!$event->job instanceof CloudTasksJob) {
                return;
            }

            $config = $event->job->cloudTasksQueue->config;

            app('queue.failer')->log(
                $config['connection'], $event->job->getQueue() ?: $config['queue'],
                $event->job->getRawBody(), $event->exception
            );
        });

        app('events')->listen(JobProcessing::class, function (JobProcessing $event) {
            if (!CloudTasks::monitorEnabled()) {
                return;
            }

            if ($event->job instanceof CloudTasksJob) {
                MonitoringService::make()->markAsRunning($event->job->uuid());
            }
        });

        app('events')->listen(JobProcessed::class, function (JobProcessed $event) {
            if (!CloudTasks::monitorEnabled()) {
                return;
            }

            if ($event->job instanceof CloudTasksJob) {
                MonitoringService::make()->markAsSuccessful($event->job->uuid());
            }
        });

        app('events')->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            if (!CloudTasks::monitorEnabled()) {
                return;
            }

            MonitoringService::make()->markAsError($event);
        });

        app('events')->listen(JobFailed::class, function ($event) {
            if (!CloudTasks::monitorEnabled()) {
                return;
            }

            MonitoringService::make()->markAsFailed($event);
        });
    }
}
