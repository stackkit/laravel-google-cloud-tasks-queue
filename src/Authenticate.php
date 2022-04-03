<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    /**
     * @return Response|never
     */
    public function handle(Request $request, Closure $next)
    {
        return CloudTasks::check($request) ? $next($request) : response()->json('', 403);
    }
}
