<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\RetryConfig;
use Illuminate\Queue\WorkerOptions;

class TaskHandler
{
    private $config;

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

    /**
     * @param $task
     * @throws CloudTasksException
     */
    public function handle($task = null)
    {
        $task = $task ?: $this->captureTask();

        $this->loadQueueConnectionConfiguration($task);

        $this->setQueue();

        OpenIdVerificator::verify(request()->bearerToken(), $this->config);

        $this->handleTask($task);
    }

    private function loadQueueConnectionConfiguration($task)
    {
        $command = unserialize($task['data']['command']);
        $connection = $command->connection ?? config('queue.default');
        $this->config = array_merge(
            config("queue.connections.{$connection}"),
            ['connection' => $connection]
        );
    }

    private function setQueue()
    {
        $this->queue = new CloudTasksQueue($this->config, $this->client);
    }

    /**
     * @throws CloudTasksException
     */
    private function captureTask()
    {
        $input = (string) (request()->getContent());

        if (!$input) {
            throw new CloudTasksException('Could not read incoming task');
        }

        $task = json_decode($input, true);

        if (is_null($task)) {
            throw new CloudTasksException('Could not decode incoming task');
        }

        return $task;
    }

    /**
     * @param $task
     * @throws CloudTasksException
     */
    private function handleTask($task)
    {
        $job = new CloudTasksJob($task, $this->queue);

        $this->loadQueueRetryConfig($job);

        $job->setAttempts((int) request()->header('X-CloudTasks-TaskExecutionCount'));
        $job->setMaxTries($this->retryConfig->getMaxAttempts());

        // If the job is being attempted again we also check if a
        // max retry duration has been set. If that duration
        // has passed, it should stop trying altogether.
        if ($job->attempts() > 0) {
            $job->setRetryUntil(CloudTasksApi::getRetryUntilTimestamp($job));
        }

        app('queue.worker')->process($this->config['connection'], $job, new WorkerOptions());
    }

    private function loadQueueRetryConfig(CloudTasksJob $job)
    {
        $queue = $job->getQueue() ?: $this->config['queue'];

        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);

        $this->retryConfig = CloudTasksApi::getRetryConfig($queueName);
    }
}
