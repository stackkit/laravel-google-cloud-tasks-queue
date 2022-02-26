<?php

declare(strict_types=1);

namespace Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Stackkit\LaravelGoogleCloudTasksQueue\StackkitCloudTask;

class StackkitCloudTaskFactory extends Factory
{
    protected $model = StackkitCloudTask::class;

    public function definition()
    {
        return [
            'status' => 'queued',
            'queue' => 'barbequeue',
            'task_uuid' => (string) Str::uuid(),
            'name' => 'SimpleJob',
            'metadata' => '{}',
            'payload' => '{}',
        ];
    }

    /**
     * Add a new cross joined sequenced state transformation to the model definition.
     *
     * @param  array  $sequence
     * @return static
     */
    public function crossJoinSequence(...$sequence)
    {
        return $this->state(new CrossJoinSequence(...$sequence));
    }
}
