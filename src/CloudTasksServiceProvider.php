<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(QueueManager $queue, Router $router)
    {
        $this->registerConnector($queue);
        $this->registerRoutes($router);
    }

    private function registerConnector(QueueManager $queue)
    {
        $queue->addConnector('cloudtasks', function () {
            return new CloudTasksConnector;
        });
    }

    private function registerRoutes(Router $router)
    {
        $router->post('handle-task', [TaskHandler::class, 'handle']);
    }
}
