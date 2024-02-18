<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job as LaravelJob;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;

use function Safe\json_encode;

class CloudTasksJob extends LaravelJob implements JobContract
{
    protected $container;

    private CloudTasksQueue $cloudTasksQueue;

    public array $job;

    protected $connectionName;

    protected $queue;

    public function __construct(Container $container, CloudTasksQueue $cloudTasksQueue, $job, $connectionName, $queue)
    {
        $this->container = $container;
        $this->cloudTasksQueue = $cloudTasksQueue;
        $this->job = $job;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function job(): array
    {
        return $this->job;
    }

    public function getJobId(): string
    {
        return $this->job['uuid'];
    }

    public function uuid(): string
    {
        return $this->job['uuid'];
    }

    public function getRawBody(): string
    {
        return json_encode($this->job);
    }

    public function attempts(): ?int
    {
        return $this->job['internal']['attempts'];
    }

    public function setAttempts(int $attempts): void
    {
        $this->job['internal']['attempts'] = $attempts;
    }

    public function getTaskName(): string
    {
        return $this->job['internal']['taskName'];
    }

    public function delete(): void
    {
        // Laravel automatically calls delete() after a job is processed successfully. However, this is
        // not what we want to happen in Cloud Tasks because Cloud Tasks will also delete the task upon
        // a 200 OK status, which means a task is deleted twice, possibly resulting in errors. So if the
        // task was processed successfully (no errors or failures) then we will not delete the task
        // manually and will let Cloud Tasks do it.
        $successful =
            // If the task has failed, we should be able to delete it permanently
            $this->hasFailed() === false
            // If the task has errored, it should be released, which in process deletes the errored task
            && $this->hasError() === false;

        if ($successful) {
            return;
        }

        parent::delete();

        $this->cloudTasksQueue->delete($this);
    }

    public function hasError(): bool
    {
        return data_get($this->job, 'internal.errored') === true;
    }

    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->cloudTasksQueue->release($this, $delay);

        if (! data_get($this->job, 'internal.errored')) {
            app('events')->dispatch(new JobReleased($this->getConnectionName(), $this, $delay));
        }
    }
}
