<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class CloudTasksServiceProvider extends LaravelServiceProvider
{
    public function boot()
    {
        $this->app['queue']->addConnector('cloudtasks', function () {
            return new CloudTasksConnector;
        });
    }
}
