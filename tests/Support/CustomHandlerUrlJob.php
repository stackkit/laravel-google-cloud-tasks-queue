<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stackkit\LaravelGoogleCloudTasksQueue\HasTaskHandlerUrl;

class CustomHandlerUrlJob implements ShouldQueue, HasTaskHandlerUrl
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        event(new JobOutput('CustomHandlerUrlJob:success'));
    }

    public function taskHandlerUrl(): string
    {
        return 'https://example.com/api/my-custom-route';
    }
}