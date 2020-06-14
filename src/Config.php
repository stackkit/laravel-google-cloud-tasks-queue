<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

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
}
