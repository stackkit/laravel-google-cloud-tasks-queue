<?php

declare(strict_types=1);

return [
    // If the application only dispatches jobs
    'disable_task_handler' => env('STACKKIT_CLOUD_TASKS_DISABLE_TASK_HANDLER', false),

    // If the application only handles jobs and is secured by already (e.g. requires Authentication)
    'disable_security_key_verification' => env('STACKKIT_CLOUD_TASKS_DISABLE_SECURITY_KEY_VERIFICATION', false),

    'dashboard' => [
        'enabled' => env('STACKKIT_CLOUD_TASKS_DASHBOARD_ENABLED', false),
        'password' => env('STACKKIT_CLOUD_TASKS_DASHBOARD_PASSWORD', 'MyPassword1!'),
    ],
];
