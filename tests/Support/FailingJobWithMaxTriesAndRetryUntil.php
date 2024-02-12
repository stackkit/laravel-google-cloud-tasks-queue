<?php

namespace Tests\Support;

use DateTimeInterface;

class FailingJobWithMaxTriesAndRetryUntil extends FailingJob
{
    public $tries = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(5);
    }
}
