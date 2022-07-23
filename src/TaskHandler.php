<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\RetryConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Safe\Exceptions\JsonException;
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

    public function handle(?string $task = null): void
    {
        $task = $this->captureTask($task);

        $this->loadQueueConnectionConfiguration($task);

        $this->setQueue();

        OpenIdVerificator::verify(request()->bearerToken(), $this->config);

        $this->handleTask($task);
    }

    /**
     * @param string|array|null $task
     * @return array
     * @throws JsonException
     */
    private function captureTask($task): array
    {
        $task = $task ?: (string) (request()->getContent());

        try {
            $array = json_decode($task, true);
        } catch (JsonException $e) {
            $array = [];
        }

        $validator = validator([
            'json' => $task,
            'task' => $array,
            'name_header' => request()->header('X-CloudTasks-Taskname'),
            'retry_count_header' => request()->header('X-CloudTasks-TaskRetryCount'),
        ], [
            'json' => 'required|json',
            'task' => 'required|array',
            'task.data' => 'required|array',
            'name_header' => 'required|string',
            'retry_count_header' => 'required|numeric',
        ]);

        try {
            $validator->validate();
        } catch (ValidationException $e) {
            if (config('app.debug')) {
                throw $e;
            } else {
                abort(404);
            }
        }

        return json_decode($task, true);
    }

    private function loadQueueConnectionConfiguration(array $task): void
    {
        /**
         * @var stdClass $command
         */
        $command = self::getCommandProperties($task['data']['command']);
        $connection = $command->connection ?? config('queue.default');
        $baseConfig = config('queue.connections.' . $connection);
        $config = (new CloudTasksConnector())->connect($baseConfig)->config;

        // The connection name from the config may not be the actual connection name
        $config['connection'] = $connection;

        $this->config = $config;
    }

    private function setQueue(): void
    {
        $this->queue = new CloudTasksQueue($this->config, $this->client);
    }

    private function handleTask(array $task): void
    {
        $job = new CloudTasksJob($task, $this->queue);

        $this->loadQueueRetryConfig($job);

        $job->setAttempts((int) request()->header('X-CloudTasks-TaskRetryCount'));
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

        $job->setAttempts($job->attempts() + 1);

        app('queue.worker')->process($this->config['connection'], $job, new WorkerOptions());
    }

    private function loadQueueRetryConfig(CloudTasksJob $job): void
    {
        $queue = $job->getQueue() ?: $this->config['queue'];

        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);

        $this->retryConfig = CloudTasksApi::getRetryConfig($queueName);
    }

    public static function getCommandProperties(string $command): array
    {
        if (Str::startsWith($command, 'O:')) {
            return (array) unserialize($command, ['allowed_classes' => false]);
        }

        if (app()->bound(Encrypter::class)) {
            return (array) unserialize(app(Encrypter::class)->decrypt($command), ['allowed_classes' => false]);
        }

        return [];
    }
}
