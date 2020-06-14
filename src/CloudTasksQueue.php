<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as LaravelQueue;

class CloudTasksQueue extends LaravelQueue implements QueueContract
{
    public function size($queue = null)
    {
        // TODO: Implement size() method.
    }

    public function push($job, $data = '', $queue = null)
    {
        // TODO: Implement push() method.
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        // TODO: Implement pushRaw() method.
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        // TODO: Implement later() method.
    }

    public function pop($queue = null)
    {
        // TODO: Implement pop() method.
    }
}
