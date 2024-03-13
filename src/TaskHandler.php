<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Container\Container;
use Illuminate\Queue\WorkerOptions;

class TaskHandler
{
    private array $config;

    public function __construct(private readonly CloudTasksClient $client)
    {
        //
    }

    public function handle(?string $task = null): void
    {
        $task = IncomingTask::fromJson($task ?: request()->getContent());

        if ($task->isEmpty()) {
            abort(422, 'Invalid task payload');
        }

        if (! CloudTasksApi::exists($task->taskName())) {
            abort(404);
        }

        $config = config('queue.connections.'.$task->connection());

        $this->config = is_array($config) ? $config : [];

        $this->run($task);
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

        tap(app('cloud-tasks.worker'), fn (Worker $worker) => $worker->process(
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
