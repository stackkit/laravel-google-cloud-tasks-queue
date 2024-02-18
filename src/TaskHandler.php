<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Container\Container;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

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
        $task = json_decode((string) $task ?: request()->getContent(), assoc: true);

        $this->config = config('queue.connections.'.$task['internal']['connection']);

        $this->guard();

        $this->handleTask($task);
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
        $queue = new CloudTasksQueue(
            config: $this->config,
            client: $this->client,
        );

        $queue->setConnectionName($task['internal']['connection']);

        $job = new CloudTasksJob(
            container: Container::getInstance(),
            cloudTasksQueue: $queue,
            job: $task,
            connectionName: $task['internal']['connection'],
            queue: $task['internal']['queue'],
        );

        try {
            CloudTasksApi::getTask($task['internal']['taskName']);
        } catch (Throwable $e) {
            if ($e instanceof ApiException && in_array($e->getStatus(), ['NOT_FOUND', 'PRECONDITION_FAILED'])) {
                abort(404);
            }

            throw $e;
        }

        $job->setAttempts($job->attempts() + 1);

        tap(app('queue.worker'), fn (Worker $worker) => $worker->process(
            connectionName: $job->getConnectionName(),
            job: $job,
            options: $this->getWorkerOptions()
        ));
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
