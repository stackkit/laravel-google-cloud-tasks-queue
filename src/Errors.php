<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

class Errors
{
    public static function invalidCredentials()
    {
        return 'Google Cloud credentials not provided. To fix this, in config/queue.php, connections.cloudtasks.credentials, provide the path to your credentials JSON file';
    }

    public static function credentialsFileDoesNotExist()
    {
        return 'Google Cloud credentials JSON file does not exist';
    }

    public static function invalidProject()
    {
        return 'Google Cloud project not provided. To fix this, set the STACKKIT_CLOUD_TASKS_PROJECT environment variable';
    }

    public static function invalidLocation()
    {
        return 'Google Cloud Tasks location not provided. To fix this, set the STACKKIT_CLOUD_TASKS_LOCATION environment variable';
    }

    public static function invalidHandler()
    {
        return 'Google Cloud Tasks handler not provided. To fix this, set the STACKKIT_CLOUD_TASKS_HANDLER environment variable';
    }

    public static function invalidServiceAccountEmail()
    {
        return 'Google Service Account email address not provided. This is needed to secure the handler so it is only accessible by Google. To fix this, set the STACKKIT_CLOUD_TASKS_SERVICE_EMAIL environment variable';
    }
}
