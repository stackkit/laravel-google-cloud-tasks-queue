<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Task;

interface CloudTasksApiContract
{
    public function createTask(string $queueName, Task $task): Task;

    public function deleteTask(string $taskName): void;

    public function getTask(string $taskName): Task;

    public function exists(string $taskName): bool;
}
