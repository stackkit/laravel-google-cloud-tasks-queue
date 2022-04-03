<?php

declare(strict_types=1);

return [
    'dashboard' => [
        'enabled' => env('STACKKIT_CLOUD_TASKS_DASHBOARD_ENABLED', false),
        'password' => env('STACKKIT_CLOUD_TASKS_DASHBOARD_PASSWORD', 'MyPassword1!')
    ],
];
