<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stackkit\LaravelGoogleCloudTasksQueue\HasTaskHeaders;

class CustomHeadersJob implements ShouldQueue, HasTaskHeaders
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        event(new JobOutput('CustomHandlerUrlJob:success'));
    }

    /** @inheritdoc */
    public function taskHeaders(): array
    {
        return [
            'X-MyJobHeader' => 'MyJobValue',
        ];
    }
}