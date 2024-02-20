<?php

declare(strict_types=1);

namespace Tests\Support;

use Error;

class FailingJob extends BaseJob
{
    public $tries = 3;

    public function handle()
    {
        throw new Error('simulating a failing job');
    }

    public function failed()
    {
        event(new JobOutput('FailingJob:failed'));
    }
}
