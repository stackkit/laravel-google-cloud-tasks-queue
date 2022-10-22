<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;
use function Safe\file_get_contents;
use function Safe\json_decode;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(): void
    {
        $this->registerClient();
        $this->registerConnector();
        $this->registerConfig();
        $this->registerViews();
        $this->registerAssets();
        $this->registerMigrations();
        $this->registerRoutes();
        $this->registerDashboard();
    }

    private function registerClient(): void
    {
        $this->app->singleton(CloudTasksClient::class, function () {
            return new CloudTasksClient();
        });

        $this->app->bind('open-id-verificator', OpenIdVerificatorConcrete::class);
        $this->app->bind('cloud-tasks-api', CloudTasksApiConcrete::class);
    }

    private function registerConnector(): void
    {
        /**
         * @var \Illuminate\Queue\QueueManager $queue
         */
        $queue = $this->app['queue'];

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
        if (CloudTasks::dashboardDisabled()) {
            // Larastan needs this view registered to check the service provider correctly.
            // return;
        }

        $this->loadViewsFrom(__DIR__ . '/../views', 'cloud-tasks');
    }

    private function registerAssets(): void
    {
        if (CloudTasks::dashboardDisabled()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../dashboard/dist' => public_path('vendor/cloud-tasks'),
        ], ['cloud-tasks']);
    }

    private function registerMigrations(): void
    {
        if (CloudTasks::dashboardDisabled()) {
            return;
        }

        $this->loadMigrationsFrom([
            __DIR__ . '/../migrations',
        ]);
    }

    private function registerRoutes(): void
    {
        /**
         * @var \Illuminate\Routing\Router $router
         */
        $router = $this->app['router'];

        $router->post('handle-task', [TaskHandler::class, 'handle'])->name('cloud-tasks.handle-task');

        if (CloudTasks::dashboardDisabled()) {
            return;
        }

        $router->post('cloud-tasks-api/login', [CloudTasksApiController::class, 'login'])->name('cloud-tasks.api.login');
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

        $router->middleware(Authenticate::class)->group(function () use ($router) {
            $router->get('cloud-tasks-api/dashboard', [CloudTasksApiController::class, 'dashboard'])->name('cloud-tasks.api.dashboard');
            $router->get('cloud-tasks-api/tasks', [CloudTasksApiController::class, 'tasks'])->name('cloud-tasks.api.tasks');
            $router->get('cloud-tasks-api/task/{uuid}', [CloudTasksApiController::class, 'task'])->name('cloud-tasks.api.task');
        });
    }

    private function registerDashboard(): void
    {
        $events = $this->app['events'];

        $events->listen(TaskCreated::class, function (TaskCreated $event) {
            if (CloudTasks::dashboardDisabled()) {
                return;
            }

            DashboardService::make()->add($event->queue, $event->task);
        });

        $events->listen(JobFailed::class, function (JobFailed $event) {
            if (!$event->job instanceof CloudTasksJob) {
                return;
            }

            $config = $event->job->cloudTasksQueue->config;

            app('queue.failer')->log(
                $config['connection'], $event->job->getQueue() ?: $config['queue'],
                $event->job->getRawBody(), $event->exception
            );
        });

        $events->listen(JobProcessing::class, function (JobProcessing $event) {
            if (!$event->job instanceof CloudTasksJob) {
                return;
            }

            if (CloudTasks::dashboardEnabled()) {
                DashboardService::make()->markAsRunning($event->job->uuid());
            }
        });

        $events->listen(JobProcessed::class, function (JobProcessed $event) {
            if (!$event->job instanceof CloudTasksJob) {
                return;
            }

            data_set($event->job->job, 'internal.processed', true);

            if (CloudTasks::dashboardEnabled()) {
                DashboardService::make()->markAsSuccessful($event->job->uuid());
            }
        });

        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            if (!$event->job instanceof CloudTasksJob) {
                return;
            }

            data_set($event->job->job, 'internal.errored', true);

            if (CloudTasks::dashboardEnabled()) {
                DashboardService::make()->markAsError($event);
            }
        });

        $events->listen(JobFailed::class, function ($event) {
            if (!$event->job instanceof CloudTasksJob) {
                return;
            }

            if (CloudTasks::dashboardEnabled()) {
                DashboardService::make()->markAsFailed($event);
            }
        });

        $events->listen(JobReleased::class, function (JobReleased $event) {
            if (!$event->job instanceof CloudTasksJob) {
                return;
            }

            if (CloudTasks::dashboardEnabled()) {
                DashboardService::make()->markAsReleased($event);
            }
        });
    }
}
