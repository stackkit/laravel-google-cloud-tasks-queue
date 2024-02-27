<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

interface HasTaskHandlerUrl
{
    public function taskHandlerUrl(): string;
}