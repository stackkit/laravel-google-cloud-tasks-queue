<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Exception;
use Illuminate\Container\Container;
use Illuminate\Queue\WorkerOptions;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskIncoming;

/**
 * @phpstan-import-type QueueConfig from CloudTasksConnector
 */
class TaskHandler
{
    /**
     * @var QueueConfig
     */
    private array $config;

    public function __construct(private readonly CloudTasksClient $client)
    {
        //
    }

    public function handle(?string $task = null): void
    {
        try {
            $task = IncomingTask::fromJson($task ?: request()->getContent());
        } catch (Exception $e) {
            abort(422, $e->getMessage());
        }

        event(new TaskIncoming($task));

        if (! CloudTasksApi::exists($task->fullyQualifiedTaskName())) {
            abort(404);
        }

        /** @var QueueConfig $config */
        $config = config('queue.connections.'.$task->connection());

        $this->config = $config;

        // We want to catch any errors so we have more fine-grained control over
        // how tasks are retried. Cloud Tasks will retry the task if a 5xx status
        // is returned. Because we manually manage retries by releasing jobs,
        // we never want to return a 5xx status as that will result in duplicate
        // job attempts.
        rescue(fn () => $this->run($task));
    }

    private function run(IncomingTask $task): void
    {
        $queue = tap(new CloudTasksQueue($this->config, $this->client))->setConnectionName($task->connection());

        $job = new CloudTasksJob(
            container: Container::getInstance(),
            driver: $queue,
            job: $task->toArray(),
            connectionName: $task->connection(),
            queue: $task->queue(),
        );

        $job->setAttempts($job->attempts() + 1);

        /** @var Worker $worker */
        $worker = app('cloud-tasks.worker');

        $worker->process(
            connectionName: $job->getConnectionName(),
            job: $job,
            options: CloudTasksQueue::getWorkerOptionsCallback() ? (CloudTasksQueue::getWorkerOptionsCallback())($task) : $this->getWorkerOptions()
        );
    }

    public function getWorkerOptions(): WorkerOptions
    {
        $options = new WorkerOptions;

        if (isset($this->config['backoff'])) {
            $options->backoff = $this->config['backoff'];
        }

        return $options;
    }
}
