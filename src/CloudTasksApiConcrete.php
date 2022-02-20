<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Attempt;
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


    public function getRetryUntilTimestamp(string $taskName): ?int
    {
        $task = $this->getTask($taskName);

        $attempt = $task->getFirstAttempt();

        if (!$attempt instanceof Attempt) {
            return null;
        }

        $queueName = implode('/', array_slice(explode('/', $task->getName()), 0, 6));

        $retryConfig = $this->getRetryConfig($queueName);

        if (! $retryConfig->hasMaxRetryDuration()) {
            return null;
        }

        $maxDurationInSeconds = $retryConfig->getMaxRetryDuration()->getSeconds();

        $firstAttemptTimestamp = $attempt->getDispatchTime()->toDateTime()->getTimestamp();

        return $firstAttemptTimestamp + $maxDurationInSeconds;
    }
}
