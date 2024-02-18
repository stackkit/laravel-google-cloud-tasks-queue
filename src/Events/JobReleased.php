<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Events;

use Illuminate\Contracts\Queue\Job;

class JobReleased
{
    public function __construct(public string $connectionName, public Job $job, public int $delay = 0)
    {
        //
    }
}
