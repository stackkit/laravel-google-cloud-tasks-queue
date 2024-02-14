<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Safe\Exceptions\JsonException;

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

    public function __construct(CloudTasksClient $client)
    {
        $this->client = $client;
    }

    public function handle(?string $task = null): void
    {
        $task = $this->captureTask($task);

        $this->loadQueueConnectionConfiguration($task);

        $this->setQueue();

        $this->guard();

        $this->handleTask($task);
    }

    /**
     * @param  string|array|null  $task
     *
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
        ], [
            'json' => 'required|json',
            'task' => 'required|array',
            'task.data' => 'required|array',
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
        $command = self::getCommandProperties($task['data']['command']);
        $connection = $command['connection'] ?? config('queue.default');
        $baseConfig = config('queue.connections.'.$connection);
        $config = (new CloudTasksConnector())->connect($baseConfig)->config;

        // The connection name from the config may not be the actual connection name
        $config['connection'] = $connection;

        $this->config = $config;
    }

    private function setQueue(): void
    {
        $this->queue = new CloudTasksQueue($this->config, $this->client);
    }

    private function guard(): void
    {
        $appEngine = ! empty($this->config['app_engine']);

        if ($appEngine) {
            // https://cloud.google.com/tasks/docs/creating-appengine-handlers#reading_task_request_headers
            // "If your request handler finds any of the headers listed above, it can trust
            // that the request is a Cloud Tasks request."
            abort_if(empty(request()->header('X-AppEngine-TaskName')), 404);
        } else {
            OpenIdVerificator::verify(request()->bearerToken(), $this->config);
        }
    }

    private function handleTask(array $task): void
    {
        $job = new CloudTasksJob($task, $this->queue);

        $fullTaskName = $this->client->taskName(
            $this->config['project'],
            $this->config['location'],
            $job->getQueue() ?: $this->config['queue'],
            request()->header('X-CloudTasks-TaskName') ?? request()->header('X-AppEngine-TaskName'),
        );

        try {
            $apiTask = CloudTasksApi::getTask($fullTaskName);
        } catch (ApiException $e) {
            if (in_array($e->getStatus(), ['NOT_FOUND', 'PRECONDITION_FAILED'])) {
                abort(404);
            }

            throw $e;
        }

        $job->setAttempts($job->attempts() + 1);

        app('queue.worker')->process($this->config['connection'], $job, $this->getWorkerOptions());
    }

    public static function getCommandProperties(string $command): array
    {
        if (Str::startsWith($command, 'O:')) {
            return (array) unserialize($command, ['allowed_classes' => false]);
        }

        if (app()->bound(Encrypter::class)) {
            return (array) unserialize(
                app(Encrypter::class)->decrypt($command),
                ['allowed_classes' => ['Illuminate\Support\Carbon']]
            );
        }

        return [];
    }

    public function getWorkerOptions(): WorkerOptions
    {
        $options = new WorkerOptions();

        if (isset($this->config['backoff'])) {
            $options->backoff = $this->config['backoff'];
        }

        return $options;
    }
}
