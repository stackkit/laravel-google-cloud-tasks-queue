<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\RetryConfig;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RetryConfig getRetryConfig(string $queueName)
 * @method static Task createTask(string $queueName, Task $task)
 * @method static void deleteTask(string $taskName)
 * @method static Task getTask(string $taskName)
 * @method static int|null getRetryUntilTimestamp(Task $task)
 */
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
