<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\DeleteTaskRequest;
use Google\Cloud\Tasks\V2\GetTaskRequest;
use Google\Cloud\Tasks\V2\Task;

class CloudTasksApiConcrete implements CloudTasksApiContract
{
    public function __construct(private readonly CloudTasksClient $client)
    {
        //
    }

    public function createTask(string $queueName, Task $task): Task
    {
        return $this->client->createTask(new CreateTaskRequest([
            'parent' => $queueName,
            'task' => $task,
        ]));
    }

    public function deleteTask(string $taskName): void
    {
        $this->client->deleteTask(new DeleteTaskRequest([
            'name' => $taskName,
        ]));
    }

    public function getTask(string $taskName): Task
    {
        return $this->client->getTask(new GetTaskRequest([
            'name' => $taskName,
        ]));
    }
}
