<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;

class SimpleJobWithTimeout extends SimpleJob
{
    public $timeout = 3;

    public function handle()
    {
        Event::listen(WorkerStopping::class, function () {
            event(new JobOutput('SimpleJobWithTimeout:worker-stopping'));
        });

        event(new JobOutput('SimpleJobWithTimeout:1'));
        sleep(1);
        event(new JobOutput('SimpleJobWithTimeout:2'));
        sleep(1);
        event(new JobOutput('SimpleJobWithTimeout:3'));
        sleep(1);
        event(new JobOutput('SimpleJobWithTimeout:4'));
        sleep(1);
        event(new JobOutput('SimpleJobWithTimeout:5'));
    }
}
