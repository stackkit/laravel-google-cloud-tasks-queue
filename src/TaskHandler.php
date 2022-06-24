<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\RetryConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\WorkerOptions;
use stdClass;
use UnexpectedValueException;
use function Safe\json_decode;

class TaskHandler
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var CloudTasksClient
     */
    private $client;

    /**
     * @var CloudTasksQueue
     */
    private $queue;

    /**
     * @var RetryConfig
     */
    private $retryConfig = null;

    public function __construct(CloudTasksClient $client)
    {
        $this->client = $client;
    }

    public function handle(?array $task = null): void
    {
        $task = $task ?: $this->captureTask();

        $this->loadQueueConnectionConfiguration($task);

        $this->setQueue();

        OpenIdVerificator::verify(request()->bearerToken(), $this->config);

        $this->handleTask($task);
    }

    private function loadQueueConnectionConfiguration(array $task): void
    {
        /**
         * @var stdClass $command
         */
        $command = unserialize($task['data']['command']);
        $connection = $command->connection ?? config('queue.default');
        $this->config = array_merge(
            (array) config("queue.connections.{$connection}"),
            ['connection' => $connection]
        );
    }

    private function setQueue(): void
    {
        $this->queue = new CloudTasksQueue($this->config, $this->client);
    }

    /**
     * @throws CloudTasksException
     */
    private function captureTask(): array
    {
        $input = (string) (request()->getContent());

        if (!$input) {
            throw new CloudTasksException('Could not read incoming task');
        }

        $task = json_decode($input, true);

        if (!is_array($task)) {
            throw new CloudTasksException('Could not decode incoming task');
        }

        return $task;
    }

    private function handleTask(array $task): void
    {
        $job = new CloudTasksJob($task, $this->queue);

        $this->loadQueueRetryConfig($job);

        $job->setAttempts((int) request()->header('X-CloudTasks-TaskExecutionCount'));
        $job->setMaxTries($this->retryConfig->getMaxAttempts());

        // If the job is being attempted again we also check if a
        // max retry duration has been set. If that duration
        // has passed, it should stop trying altogether.
        if ($job->attempts() > 0) {
            $taskName = request()->header('X-Cloudtasks-Taskname');

            if (!is_string($taskName)) {
                throw new UnexpectedValueException('Expected task name to be a string.');
            }

            $fullTaskName = $this->client->taskName(
                $this->config['project'],
                $this->config['location'],
                $job->getQueue() ?: $this->config['queue'],
                $taskName,
            );

            $job->setRetryUntil(CloudTasksApi::getRetryUntilTimestamp($fullTaskName));
        }

        app('queue.worker')->process($this->config['connection'], $job, new WorkerOptions());
    }

    private function loadQueueRetryConfig(CloudTasksJob $job): void
    {
        $queue = $job->getQueue() ?: $this->config['queue'];

        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);

        $this->retryConfig = CloudTasksApi::getRetryConfig($queueName);
    }
}
