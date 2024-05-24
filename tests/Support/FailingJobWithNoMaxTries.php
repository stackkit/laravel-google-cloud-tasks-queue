<?php

declare(strict_types=1);

namespace Tests\Support;

class FailingJobWithNoMaxTries extends FailingJob
{
    public $tries = null;
}
