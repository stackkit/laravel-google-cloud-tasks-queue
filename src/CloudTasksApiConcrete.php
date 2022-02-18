<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\RetryConfig;
use Google\Cloud\Tasks\V2\Task;

class CloudTasksApiConcrete implements CloudTasksApiContract
{
    /**
     * @var CloudTasksClient $client
     */
    private $client;

    public function __construct(CloudTasksClient $client)
    {
        $this->client = $client;
    }

    public function getRetryConfig(string $queueName): RetryConfig
    {
        return $this->client->getQueue($queueName)->getRetryConfig();
    }

    public function createTask(string $queueName, Task $task): Task
    {
        // TODO: Implement createTask() method.
    }

    public function deleteTask(string $taskName): void
    {
        // TODO: Implement deleteTask() method.
    }

    public function getRetryUntilTimestamp(CloudTasksJob $job): ?int
    {
        // TODO: Implement getRetryUntilTimestamp() method.
    }
}
