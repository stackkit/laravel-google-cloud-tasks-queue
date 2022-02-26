<?php

declare(strict_types=1);

namespace Factories;

/** @var \Illuminate\Database\Eloquent\Factory $factory */

use Illuminate\Support\Str;
use Stackkit\LaravelGoogleCloudTasksQueue\StackkitCloudTask;
use Faker\Generator as Faker;

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
