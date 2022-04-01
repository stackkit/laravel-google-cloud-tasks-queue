<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Carbon\Carbon;
use Closure;
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

        if (!$token) {
            return false;
        }

        try {
            $expireTimestamp = decrypt($token);

            return $expireTimestamp > Carbon::now()->timestamp;
        } catch (Throwable $e) {
            return  false;
        }
    }

    /**
     * Determine if the monitor is enabled.
     *
     * @return bool
     */
    public static function monitorEnabled(): bool
    {
        return config('cloud-tasks.monitor.enabled') === true;
    }

    /**
     * Determine if the monitor is disabled.
     *
     * @return bool
     */
    public static function monitorDisabled(): bool
    {
        return self::monitorEnabled() === false;
    }
}
