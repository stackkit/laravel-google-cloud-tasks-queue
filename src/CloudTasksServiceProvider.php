<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(): void
    {
        $this->registerClient();
        $this->registerConnector();
        $this->registerConfig();
        $this->registerRoutes();
        $this->registerEvents();
    }

    private function registerClient(): void
    {
        $this->app->singleton(CloudTasksClient::class, function () {
            return new CloudTasksClient();
        });

        $this->app->singleton('cloud-tasks.worker', function (Application $app) {
            return new Worker(
                $app['queue'],
                $app['events'],
                $app[ExceptionHandler::class],
                fn () => $app->isDownForMaintenance(),
            );
        });

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
            __DIR__.'/../config/cloud-tasks.php' => config_path('cloud-tasks.php'),
        ], ['cloud-tasks']);

        $this->mergeConfigFrom(__DIR__.'/../config/cloud-tasks.php', 'cloud-tasks');
    }

    private function registerRoutes(): void
    {
        if (config('cloud-tasks.disable_task_handler')) {
            return;
        }

        /**
         * @var \Illuminate\Routing\Router $router
         */
        $router = $this->app['router'];

        $router->post(config('cloud-tasks.uri'), [TaskHandler::class, 'handle'])->name('cloud-tasks.handle-task');
    }

    private function registerEvents(): void
    {
        $events = $this->app['events'];

        $events->listen(JobFailed::class, function (JobFailed $event) {
            if (! $event->job instanceof CloudTasksJob) {
                return;
            }

            app('queue.failer')->log(
                $event->job->getConnectionName(),
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception,
            );
        });

        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            if (! $event->job instanceof CloudTasksJob) {
                return;
            }

            data_set($event->job->job, 'internal.errored', true);
        });

        $events->listen(JobFailed::class, function ($event) {
            if (! $event->job instanceof CloudTasksJob) {
                return;
            }
        });

        $events->listen(JobReleased::class, function (JobReleased $event) {
            if (! $event->job instanceof CloudTasksJob) {
                return;
            }
        });
    }
}
