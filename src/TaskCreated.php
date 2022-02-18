<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Task;

class TaskCreated
{
    public Task $task;

    public function __construct(Task $task)
    {
        $this->task = $task;
    }
}
