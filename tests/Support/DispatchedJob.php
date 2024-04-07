<?php

declare(strict_types=1);

namespace Tests\Support;

use Error;
use Google\Cloud\Tasks\V2\Task;
use Tests\TestCase;

class DispatchedJob
{
    public string $payload;

    public Task $task;

    public TestCase $testCase;

    public function __construct(string $payload, Task $task, TestCase $testCase)
    {
        $this->payload = $payload;
        $this->task = $task;
        $this->testCase = $testCase;
    }

    public function run(): void
    {
        $header = match (true) {
            $this->task->hasHttpRequest() => 'HTTP_X_CLOUDTASKS_TASKNAME',
            $this->task->hasAppEngineHttpRequest() => 'HTTP_X_APPENGINE_TASKNAME',
            default => throw new Error('Task does not have a request.'),
        };


        $this->testCase->call(
            method: 'POST',
            uri: route('cloud-tasks.handle-task'),
            server: [
                $header => (string) str($this->task->getName())->after('/tasks/'),
            ],
            content: $this->payload,
        );
    }

    public function runAndGetReleasedJob(): self
    {
        $this->run();

        $releasedTask = end($this->testCase->createdTasks);

        if (! $releasedTask) {
            $this->testCase->fail('No task was released.');
        }

        $payload = $releasedTask->getAppEngineHttpRequest()?->getBody()
            ?: $releasedTask->getHttpRequest()->getBody();

        return new self(
            $payload,
            $releasedTask,
            $this->testCase
        );
    }
}
