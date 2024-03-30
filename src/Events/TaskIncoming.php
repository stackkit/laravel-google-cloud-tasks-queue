<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Events;

use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;

class TaskIncoming
{
    public function __construct(public IncomingTask $task)
    {
        //
    }
}
