<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Exception;
use Google\Cloud\Tasks\V2\Attempt;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\RetryConfig;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Duration;
use Google\Protobuf\Timestamp;

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
        $retryConfig = $this->client->getQueue($queueName)->getRetryConfig();

        if (! $retryConfig instanceof RetryConfig) {
            throw new Exception('Queue does not have a retry config.');
        }

        return $retryConfig;
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

    public function getRetryUntilTimestamp(Task $task): ?int
    {
        $attempt = $task->getFirstAttempt();

        if (!$attempt instanceof Attempt) {
            return null;
        }

        $queueName = implode('/', array_slice(explode('/', $task->getName()), 0, 6));

        $retryConfig = $this->getRetryConfig($queueName);

        $maxRetryDuration = $retryConfig->getMaxRetryDuration();
        $dispatchTime = $attempt->getDispatchTime();

        if (! $maxRetryDuration instanceof Duration || ! $dispatchTime instanceof Timestamp) {
            return null;
        }

        $maxDurationInSeconds = (int) $maxRetryDuration->getSeconds();

        $firstAttemptTimestamp = $dispatchTime->toDateTime()->getTimestamp();

        return $firstAttemptTimestamp + $maxDurationInSeconds;
    }
}
