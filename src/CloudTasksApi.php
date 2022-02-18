<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Support\Facades\Facade;

class CloudTasksApi extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'cloud-tasks-api';
    }

    public static function fake(): void
    {
        self::swap(new CloudTasksApiFake());
    }
}
