<?php

declare(strict_types=1);

namespace Tests\Support;

class FailingJobWithMaxTries extends FailingJob
{
    public $tries = 3;
}
