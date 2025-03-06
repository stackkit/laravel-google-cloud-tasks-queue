<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Task;
use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\GetTaskRequest;
use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\DeleteTaskRequest;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;

class CloudTasksApiConcrete implements CloudTasksApiContract
{
    public function __construct(private readonly CloudTasksClient $client)
    {
        //
    }

    /**
     * @throws ApiException
     */
    public function createTask(string $queueName, Task $task): Task
    {
        return $this->client->createTask(new CreateTaskRequest([
            'parent' => $queueName,
            'task' => $task,
        ]));
    }

    /**
     * @throws ApiException
     */
    public function deleteTask(string $taskName): void
    {
        $this->client->deleteTask(new DeleteTaskRequest([
            'name' => $taskName,
        ]));
    }

    /**
     * @throws ApiException
     */
    public function getTask(string $taskName): Task
    {
        return $this->client->getTask(new GetTaskRequest([
            'name' => $taskName,
        ]));
    }

    public function exists(string $taskName): bool
    {
        try {
            $this->getTask($taskName);

            return true;
        } catch (ApiException $e) {
            if ($e->getStatus() === 'NOT_FOUND') {
                return false;
            }

            report($e);
        }

        return false;
    }
}
