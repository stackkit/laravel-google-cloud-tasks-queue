<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot(QueueManager $queue, Router $router)
    {
        $this->registerClient();
        $this->registerConnector($queue);
        $this->registerRoutes($router);
    }

    private function registerClient()
    {
        $this->app->singleton(CloudTasksClient::class, function () {
            return new CloudTasksClient([
                'credentials' => Config::credentials(),
            ]);
        });
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
