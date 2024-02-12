<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

class Errors
{
    public static function invalidProject(): string
    {
        return 'Google Cloud project not provided. To fix this, set the STACKKIT_CLOUD_TASKS_PROJECT environment variable';
    }

    public static function invalidLocation(): string
    {
        return 'Google Cloud Tasks location not provided. To fix this, set the STACKKIT_CLOUD_TASKS_LOCATION environment variable';
    }

    public static function invalidServiceAccountEmail(): string
    {
        return 'Google Service Account email address not provided. This is needed to secure the handler so it is only accessible by Google. To fix this, set the STACKKIT_CLOUD_TASKS_SERVICE_EMAIL environment variable';
    }
}
