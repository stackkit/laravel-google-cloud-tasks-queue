<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Routing\Router;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\Commands\WorkCloudRunJob;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(): void
    {
        $this->registerClient();
        $this->registerConnector();
        $this->registerConfig();
        $this->registerRoutes();
        $this->registerEvents();
        $this->registerCommands();
    }

    private function registerClient(): void
    {
        $this->app->singleton(CloudTasksClient::class, function () {
            return new CloudTasksClient(config()->array('cloud-tasks.client_options', []));
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
        with(resolve('queue'), function (QueueManager $queue) {
            $queue->addConnector('cloudtasks', function () {
                return new CloudTasksConnector;
            });
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

        with(resolve('router'), function (Router $router) {
            $router->post(config()->string('cloud-tasks.uri'), [TaskHandler::class, 'handle'])
                ->name('cloud-tasks.handle-task');
        });
    }

    private function registerEvents(): void
    {
        /** @var Dispatcher $events */
        $events = app('events');

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

            $event->job->job['internal']['errored'] = true;
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

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkCloudRunJob::class,
            ]);
        }
    }
}
