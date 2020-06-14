<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job as LaravelJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class CloudTasksJob extends LaravelJob implements JobContract
{
    private $job;
    private $attempts;

    public function __construct($job, $attempts)
    {
        $this->job = $job;
        $this->attempts = $attempts;
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
}
