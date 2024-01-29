<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\ApiCore\ApiException;
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
        // test
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
        ], [
            'json' => 'required|json',
            'task' => 'required|array',
            'task.data' => 'required|array',
            'name_header' => 'required|string',
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

        $taskName = request()->header('X-Cloudtasks-Taskname');
        $fullTaskName = $this->client->taskName(
            $this->config['project'],
            $this->config['location'],
            $job->getQueue() ?: $this->config['queue'],
            $taskName,
        );

        try {
            $apiTask = CloudTasksApi::getTask($fullTaskName);
        } catch (ApiException $e) {
            if (in_array($e->getStatus(), ['NOT_FOUND', 'PRECONDITION_FAILED'])) {
                abort(404);
            }

            throw $e;
        }

        // If the task has a [X-CloudTasks-TaskRetryCount] header higher than 0, then
        // we know the job was created using an earlier version of the package. This
        // job does not have the attempts tracked internally yet.
        $taskRetryCountHeader = request()->header('X-CloudTasks-TaskRetryCount');
        if ($taskRetryCountHeader && (int) $taskRetryCountHeader > 0) {
            $job->setAttempts((int) $taskRetryCountHeader);
        } else {
            $job->setAttempts($task['internal']['attempts']);
        }

        $job->setMaxTries($this->retryConfig->getMaxAttempts());

        // If the job is being attempted again we also check if a
        // max retry duration has been set. If that duration
        // has passed, it should stop trying altogether.
        if ($job->attempts() > 0) {
            $job->setRetryUntil(CloudTasksApi::getRetryUntilTimestamp($apiTask));
        }

        $job->setAttempts($job->attempts() + 1);

        app('queue.worker')->process($this->config['connection'], $job, $this->getWorkerOptions());
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
            return (array) unserialize(app(Encrypter::class)->decrypt($command), ['allowed_classes' => ['Illuminate\Support\Carbon']]);
        }

        return [];
    }

    public function getWorkerOptions(): WorkerOptions
    {
        $options = new WorkerOptions();

        $prop = version_compare(app()->version(), '8.0.0', '<') ? 'delay' : 'backoff';

        $options->$prop = $this->config['backoff'] ?? 0;

        return $options;
    }
}
