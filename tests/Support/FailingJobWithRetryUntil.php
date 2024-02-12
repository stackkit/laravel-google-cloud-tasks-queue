<?php

namespace Tests\Support;

use DateTimeInterface;

class FailingJobWithRetryUntil extends FailingJob
{
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(5);
    }
}
