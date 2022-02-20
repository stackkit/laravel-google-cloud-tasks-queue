<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job as LaravelJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class CloudTasksJob extends LaravelJob implements JobContract
{
    private $job;
    private $attempts;
    private $maxTries;
    public $retryUntil = null;

    /**
     * @var CloudTasksQueue
     */
    public $cloudTasksQueue;

    public function __construct($job, CloudTasksQueue $cloudTasksQueue)
    {
        $this->job = $job;
        $this->container = Container::getInstance();
        $this->cloudTasksQueue = $cloudTasksQueue;
        $command = unserialize($job['data']['command']);
        $this->queue = $command->queue;
    }

    public function getJobId()
    {
        return $this->job['uuid'];
    }

    public function uuid(): string
    {
        return $this->job['uuid'];
    }

    public function getRawBody()
    {
        return json_encode($this->job);
    }

    public function attempts()
    {
        return $this->attempts;
    }

    public function setAttempts($attempts)
    {
        $this->attempts = $attempts;
    }

    public function setMaxTries($maxTries)
    {
        if ((int) $maxTries === -1) {
            $maxTries = 0;
        }

        $this->maxTries = $maxTries;
    }

    public function maxTries()
    {
        return $this->maxTries;
    }

    public function setQueue($queue)
    {
        $this->queue = $queue;
    }

    public function setRetryUntil($retryUntil)
    {
        $this->retryUntil = $retryUntil;
    }

    public function retryUntil()
    {
        return $this->retryUntil;
    }

    // timeoutAt was renamed to retryUntil in 8.x but we still support this.
    public function timeoutAt()
    {
        return $this->retryUntil;
    }

    public function delete()
    {
        parent::delete();

        $this->cloudTasksQueue->delete($this);
    }

    public function fire()
    {
        $this->attempts++;

        parent::fire();
    }
}
