<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Auth\AccessToken;

class OpenIdVerificatorFake
{
    public function verify(?string $token, array $config): void
    {
        if (!$token) {
            return;
        }

        (new AccessToken())->verify(
            $token,
            [
                'audience' => Config::getAudience($config),
                'throwException' => true,
                'certsLocation' => __DIR__ . '/../tests/Support/self-signed-public-key-as-jwk.json',
            ]
        );
    }
}
