<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job as LaravelJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class CloudTasksJob extends LaravelJob implements JobContract
{
    private $job;
    private $attempts;
    private $maxTries;

    public function __construct($job)
    {
        $this->job = $job;
        $this->container = Container::getInstance();
    }

    public function getJobId()
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
            $maxTries = null;
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
}
