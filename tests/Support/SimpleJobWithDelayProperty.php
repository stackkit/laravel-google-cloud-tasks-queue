<?php

declare(strict_types=1);

namespace Tests\Support;

class SimpleJobWithDelayProperty extends BaseJob
{
    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($delay = null)
    {
        $this->delay = $delay;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        event(new JobOutput('SimpleJobWithDelayProperty:success'));
    }
}
