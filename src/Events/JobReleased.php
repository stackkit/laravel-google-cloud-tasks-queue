<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Events;

use Illuminate\Contracts\Queue\Job;

class JobReleased
{
    /**
     * The connection name.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * The job instance.
     *
     * @var Job
     */
    public Job $job;

    /**
     * The job delay in seconds.
     *
     * @var int
     */
    public int $delay;

    /**
     * Create a new event instance.
     *
     * @param  string  $connectionName
     * @param  Job  $job
     * @param int $delay
     * @return void
     */
    public function __construct(string $connectionName, Job $job, int $delay = 0)
    {
        $this->job = $job;
        $this->connectionName = $connectionName;
        $this->delay = $delay;
    }
}
