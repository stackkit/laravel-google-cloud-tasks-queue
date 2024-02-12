<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;

class CloudTasksApiConcrete implements CloudTasksApiContract
{
    /**
     * @var CloudTasksClient
     */
    private $client;

    public function __construct(CloudTasksClient $client)
    {
        $this->client = $client;
    }

    public function createTask(string $queueName, Task $task): Task
    {
        return $this->client->createTask($queueName, $task);
    }

    public function deleteTask(string $taskName): void
    {
        $this->client->deleteTask($taskName);
    }

    public function getTask(string $taskName): Task
    {
        return $this->client->getTask($taskName);
    }
}
