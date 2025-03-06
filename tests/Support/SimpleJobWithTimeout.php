<?php

declare(strict_types=1);

namespace Tests\Support;

use Error;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\WorkerStopping;
use Symfony\Component\ErrorHandler\Error\FatalError;

class SimpleJobWithTimeout extends SimpleJob
{
    public $timeout = 3;

    public function handle()
    {
        throw new FatalError('Maximum execution time of 30 seconds exceeded', 500, [
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
    }
}
