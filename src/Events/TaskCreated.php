<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Events;

use Google\Cloud\Tasks\V2\Task;

class TaskCreated
{
    public string $queue;
    public Task $task;

    public function task(Task $task): self
    {
        $this->task = $task;

        return $this;
    }

    public function queue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }
}
