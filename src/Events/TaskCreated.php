<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Events;

use Google\Cloud\Tasks\V2\Task;

class TaskCreated
{
    public function __construct(public string $queue, public Task $task)
    {
        //
    }
}
