<?php

declare(strict_types=1);

namespace Tests\Support;

class JobThatWillBeReleased extends BaseJob
{
    public $tries = 3;

    public function __construct(private int $releaseDelay = 0)
    {
        //
    }

    public function handle()
    {
        $this->release($this->releaseDelay);
    }
}
