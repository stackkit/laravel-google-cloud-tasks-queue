<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class JobThatWillBeReleased implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $releaseDelay;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $releaseDelay = 0)
    {
        $this->releaseDelay = $releaseDelay;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        logger('JobThatWillBeReleased:beforeRelease');
        $this->release($this->releaseDelay);
        logger('JobThatWillBeReleased:afterRelease');
    }
}
