<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Error;

class Config
{
    public static function validate(array $config): void
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
