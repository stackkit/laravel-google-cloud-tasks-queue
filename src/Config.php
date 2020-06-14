<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Error;

class Config
{
    public static function credentials()
    {
        return config('queue.connections.cloudtasks.credentials');
    }

    public static function project()
    {
        return config('queue.connections.cloudtasks.project');
    }

    public static function location()
    {
        return config('queue.connections.cloudtasks.location');
    }

    public static function handler()
    {
        return config('queue.connections.cloudtasks.handler');
    }

    public static function validate(array $config)
    {
        if (empty($config['credentials'])) {
            throw new Error(Errors::invalidCredentials());
        }

        if (!file_exists($config['credentials'])) {
            throw new Error(Errors::credentialsFileDoesNotExist());
        }

        if (empty($config['project'])) {
            throw new Error(Errors::invalidProject());
        }

        if (empty($config['location'])) {
            throw new Error(Errors::invalidLocation());
        }

        if (empty($config['handler'])) {
            throw new Error(Errors::invalidHandler());
        }
    }
}
