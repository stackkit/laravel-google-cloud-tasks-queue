<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\RetryConfig;
use Google\Cloud\Tasks\V2\Task;

interface CloudTasksApiContract
{
    public function getRetryConfig(string $queueName): RetryConfig;
    public function createTask(string $queueName, Task $task): Task;
    public function deleteTask(string $taskName): void;
    public function getTask(string $taskName): Task;
    public function getRetryUntilTimestamp(Task $task): ?int;
}
