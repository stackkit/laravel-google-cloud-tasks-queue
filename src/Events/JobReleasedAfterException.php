<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Events;

use Illuminate\Contracts\Queue\Job;

class JobReleasedAfterException
{
    /**
     * The connection name.
     *
     * @var string
     */
    public $connectionName;

    /**
     * The job instance.
     *
     * @var Job
     */
    public $job;

    /**
     * Create a new event instance.
     *
     * @param  string  $connectionName
     * @param  Job  $job
     * @return void
     */
    public function __construct(string $connectionName, Job $job)
    {
        $this->job = $job;
        $this->connectionName = $connectionName;
    }
}
