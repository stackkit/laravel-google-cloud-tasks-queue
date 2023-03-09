<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job as LaravelJob;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleasedAfterException;
use function Safe\json_encode;

class CloudTasksJob extends LaravelJob implements JobContract
{
    /**
     * The Cloud Tasks raw job payload (request payload).
     *
     * @var array
     */
    public array $job;

    private ?int $maxTries;
    public ?int $retryUntil = null;

    /**
     * @var CloudTasksQueue
     */
    public $cloudTasksQueue;

    public function __construct(array $job, CloudTasksQueue $cloudTasksQueue)
    {
        $this->job = $job;
        $this->container = Container::getInstance();
        $this->cloudTasksQueue = $cloudTasksQueue;
        
        $command = TaskHandler::getCommandProperties($job['data']['command']);
        $this->queue = $command['queue'] ?? config('queue.connections.' .config('queue.default') . '.queue');
    }

    public function job()
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

    public function setMaxTries(int $maxTries): void
    {
        if ($maxTries === -1) {
            $maxTries = 0;
        }

        $this->maxTries = $maxTries;
    }

    public function maxTries(): ?int
    {
        return $this->maxTries;
    }

    public function setQueue(string $queue): void
    {
        $this->queue = $queue;
    }

    public function setRetryUntil(?int $retryUntil): void
    {
        $this->retryUntil = $retryUntil;
    }

    public function retryUntil(): ?int
    {
        return $this->retryUntil;
    }

    // timeoutAt was renamed to retryUntil in 8.x but we still support this.
    public function timeoutAt(): ?int
    {
        return $this->retryUntil;
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

    public function release($delay = 0)
    {
        parent::release();

        $this->cloudTasksQueue->release($this, $delay);

        $properties = TaskHandler::getCommandProperties($this->job['data']['command']);
        $connection = $properties['connection'] ?? config('queue.default');

        // The package uses the JobReleasedAfterException provided by Laravel to grab
        // the payload of the released job in tests to easily run and test a released
        // job. Because the event is only accessible in Laravel 9.x, we create an
        // identical event to hook into for Laravel versions older than 9.x
        if (version_compare(app()->version(), '9.0.0', '<')) {
            if (data_get($this->job, 'internal.errored')) {
                app('events')->dispatch(new JobReleasedAfterException($connection, $this));
            }
        }

        if (! data_get($this->job, 'internal.errored')) {
            app('events')->dispatch(new JobReleased($connection, $this, $delay));
        }
    }
}
