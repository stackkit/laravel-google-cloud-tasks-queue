<?php

declare(strict_types=1);

namespace Factories;

/** @var \Illuminate\Database\Eloquent\Factory $factory */

use Faker\Generator as Faker;
use Illuminate\Support\Str;
use Stackkit\LaravelGoogleCloudTasksQueue\StackkitCloudTask;

$factory->define(StackkitCloudTask::class, function (Faker $faker) {
    return [
        'status' => 'queued',
        'queue' => 'barbequeue',
        'task_uuid' => (string) Str::uuid(),
        'name' => 'SimpleJob',
        'metadata' => '{}',
        'payload' => '{}',
    ];
});
