<?php

namespace Tests\Support;

class FailingJobWithMaxTries extends FailingJob
{
    public $tries = 3;
}
