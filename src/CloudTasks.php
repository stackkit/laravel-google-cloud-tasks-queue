<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Closure;

class CloudTasks
{
    /**
     * The callback that should be used to authenticate Cloud Tasks users.
     *
     * @var \Closure
     */
    public static $authUsing;

    /**
     * Set the callback that should be used to authenticate Horizon users.
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function auth(Closure $callback)
    {
        static::$authUsing = $callback;

        return new static;
    }

    /**
     * Determine if the given request can access the Cloud Tasks dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public static function check($request)
    {
        return (static::$authUsing ?: function () {
            return app()->environment('local');
        })($request);
    }
}