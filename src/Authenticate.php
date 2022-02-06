<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

class Authenticate
{
    public function handle($request, $next)
    {
        return CloudTasks::check($request) ? $next($request) : abort(403);
    }
}