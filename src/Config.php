<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Error;

class Config
{
    public static function credentials($connection = 'cloudtasks')
    {
        return config("queue.connections.{$connection}.credentials");
    }

    public static function project($connection = 'cloudtasks')
    {
        return config("queue.connections.{$connection}.project");
    }

    public static function location($connection = 'cloudtasks')
    {
        return config("queue.connections.{$connection}.location");
    }

    public static function handler($connection = 'cloudtasks')
    {
        return config("queue.connections.{$connection}.handler");
    }

    public static function serviceAccountEmail($connection = 'cloudtasks')
    {
        return config("queue.connections.{$connection}.service_account_email");
    }

    public static function validate(array $config)
    {
        if (empty($config['project'])) {
            throw new Error(Errors::invalidProject());
        }

        if (empty($config['location'])) {
            throw new Error(Errors::invalidLocation());
        }

        if (empty($config['handler'])) {
            throw new Error(Errors::invalidHandler());
        }

        if (empty($config['service_account_email'])) {
            throw new Error(Errors::invalidServiceAccountEmail());
        }
    }
}
