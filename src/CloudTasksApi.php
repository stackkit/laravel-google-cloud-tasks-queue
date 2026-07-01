<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Task;
use Google\Cloud\Tasks\V2\Queue;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Task createTask(string $queueName, Task $task)
 * @method static void deleteTask(string $taskName)
 * @method static Task getTask(string $taskName)
 * @method static bool exists(string $taskName)
 * @method static Queue pause(string $queue)
 * @method static Queue resume(string $queue)
 */
class CloudTasksApi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cloud-tasks-api';
    }

    public static function fake(): void
    {
        self::swap(new CloudTasksApiFake);
    }
}
