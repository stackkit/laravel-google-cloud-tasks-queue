<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Auth\AccessToken;
use Illuminate\Support\Facades\Facade;

class OpenIdVerificatorConcrete extends Facade
{
    public function verify(?string $token, array $config): void
    {
        if (!$token) {
            throw new CloudTasksException('Missing [Authorization] header');
        }

        (new AccessToken())->verify(
            $token,
            [
                'audience' => Config::getAudience($config),
                'throwException' => true,
            ]
        );
    }
}
