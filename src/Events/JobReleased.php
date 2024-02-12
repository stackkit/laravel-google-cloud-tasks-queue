<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Events;

use Illuminate\Contracts\Queue\Job;

class JobReleased
{
    /**
     * The connection name.
     */
    public string $connectionName;

    /**
     * The job instance.
     */
    public Job $job;

    /**
     * The job delay in seconds.
     */
    public int $delay;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $connectionName, Job $job, int $delay = 0)
    {
        $this->job = $job;
        $this->connectionName = $connectionName;
        $this->delay = $delay;
    }
}
