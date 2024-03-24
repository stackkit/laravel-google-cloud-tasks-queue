<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Exception;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job as LaravelJob;
use Safe\Exceptions\JsonException;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;

use function Safe\json_encode;

class CloudTasksJob extends LaravelJob implements JobContract
{
    protected $container;

    private CloudTasksQueue $driver;

    public array $job;

    protected $connectionName;

    protected $queue;

    public function __construct(
        Container $container,
        CloudTasksQueue $driver,
        array $job,
        string $connectionName,
        string $queue)
    {
        $this->container = $container;
        $this->driver = $driver;
        $this->job = $job;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return $this->uuid() ?? throw new Exception();
    }

    /**
     * @throws JsonException
     */
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
        // Laravel automatically calls delete() after a job is processed successfully.
        // However, this is not what we want to happen in Cloud Tasks because Cloud Tasks
        // will also delete the task upon a 200 OK status, which means a task is deleted twice.
    }

    public function hasError(): bool
    {
        return data_get($this->job, 'internal.errored') === true;
    }

    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->driver->release($this, $delay);

        if (! data_get($this->job, 'internal.errored')) {
            app('events')->dispatch(new JobReleased($this->getConnectionName(), $this, $delay));
        }
    }
}
