<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;

class EncryptedJob extends BaseJob implements ShouldBeEncrypted
{
    public function handle()
    {
        event(new JobOutput('EncryptedJob:success'));
    }
}
