<?php

declare(strict_types=1);

namespace Tests\Support;

class SimpleJob extends BaseJob
{
    public $tries = 3;

    public $id = 0;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        event(new JobOutput('SimpleJob:success'));
    }
}
