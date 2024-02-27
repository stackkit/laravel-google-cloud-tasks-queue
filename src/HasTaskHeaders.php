<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

interface HasTaskHeaders
{
    /** @return array<string, mixed> */
    public function taskHeaders(): array;
}