<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Carbon\Carbon;
use Throwable;

final class CloudTasks
{
    /**
     * Determine if the given request can access the Cloud Tasks dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public static function check($request)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return false;
        }

        try {
            $expireTimestamp = decrypt($token);

            return $expireTimestamp > Carbon::now()->timestamp;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Determine if the dashboard is enabled.
     */
    public static function dashboardEnabled(): bool
    {
        return config('cloud-tasks.dashboard.enabled') === true;
    }

    /**
     * Determine if the dashboard is disabled.
     */
    public static function dashboardDisabled(): bool
    {
        return self::dashboardEnabled() === false;
    }
}
