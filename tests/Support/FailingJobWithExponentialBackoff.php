<?php

declare(strict_types=1);

namespace Tests\Support;

class FailingJobWithExponentialBackoff extends FailingJob
{
    public $tries = 5;

    public function backoff(): array
    {
        return [50, 60, 70];
    }
}
