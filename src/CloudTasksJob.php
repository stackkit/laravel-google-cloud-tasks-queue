<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Queue\Jobs\Job as LaravelJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class CloudTasksJob extends LaravelJob implements JobContract
{
    public function getJobId()
    {
        // TODO: Implement getJobId() method.
    }

    public function getRawBody()
    {
        // TODO: Implement getRawBody() method.
    }

    public function attempts()
    {
        // TODO: Implement attempts() method.
    }
}
