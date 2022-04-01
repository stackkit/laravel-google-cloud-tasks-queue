<?php

declare(strict_types=1);

return [
    'monitor' => [
        'enabled' => env('CLOUD_TASKS_MONITOR_ENABLED', false),
        'password' => env('CLOUD_TASKS_MONITOR_PASSWORD', '$2a$12$q3pRT5jjjjPlTSaGhoy.gupULnK.5lQEiquK5RVaWbGw9nYRy7gwi') // MyPassword1!
    ],
];
