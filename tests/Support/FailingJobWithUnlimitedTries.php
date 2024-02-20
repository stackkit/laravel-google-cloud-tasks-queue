<?php

declare(strict_types=1);

namespace Tests\Support;

class FailingJobWithUnlimitedTries extends FailingJob
{
    public $tries = 500;
}
