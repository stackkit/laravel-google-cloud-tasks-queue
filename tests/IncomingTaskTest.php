<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Str;
use Tests\Support\SimpleJob;
use Tests\Support\EncryptedJob;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskIncoming;

class IncomingTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CloudTasksApi::fake();

        Event::fake(TaskIncoming::class);
    }

    #[Test]
    #[TestWith([SimpleJob::class, 'cloudtasks'])]
    #[TestWith([SimpleJob::class, 'appengine'])]
    #[TestWith([EncryptedJob::class, 'cloudtasks'])]
    #[TestWith([EncryptedJob::class, 'appengine'])]
    public function it_reads_the_incoming_task(string $job, string $taskType)
    {
        // Arrange
        $this->withTaskType($taskType);
        Str::createUlidsUsingSequence(['01HSR4V9QE2F4T0K8RBAYQ88KE']);

        // Act
        $this->dispatch(new $job)->run();

        // Assert
        Event::assertDispatched(function (TaskIncoming $event) use ($job) {
            return $event->task->fullyQualifiedTaskName() === 'projects/my-test-project/locations/europe-west6/queues/barbequeue/tasks/01HSR4V9QE2F4T0K8RBAYQ88KE-'.class_basename($job)
                && $event->task->connection() === 'my-cloudtasks-connection'
                && $event->task->queue() === 'barbequeue';
        });
    }

    #[Test]
    #[TestWith([SimpleJob::class, 'cloudtasks'])]
    #[TestWith([SimpleJob::class, 'appengine'])]
    #[TestWith([EncryptedJob::class, 'cloudtasks'])]
    #[TestWith([EncryptedJob::class, 'appengine'])]
    public function it_reads_the_custom_queue(string $job, string $taskType)
    {
        // Arrange
        $this->withTaskType($taskType);

        // Act
        $this->dispatch((new $job)->onQueue('other-queue'))->run();

        // Assert
        Event::assertDispatched(function (TaskIncoming $event) {
            return $event->task->queue() === 'other-queue';
        });
    }

    #[Test]
    #[TestWith([SimpleJob::class, 'cloudtasks'])]
    #[TestWith([SimpleJob::class, 'appengine'])]
    #[TestWith([EncryptedJob::class, 'cloudtasks'])]
    #[TestWith([EncryptedJob::class, 'appengine'])]
    public function it_reads_the_custom_connection(string $job, string $taskType)
    {
        // Arrange
        $this->withTaskType($taskType);

        // Act
        $this->dispatch((new $job)->onConnection('my-other-cloudtasks-connection'))->run();

        // Assert
        Event::assertDispatched(function (TaskIncoming $event) {
            return $event->task->connection() === 'my-other-cloudtasks-connection'
                && $event->task->queue() === 'other-barbequeue';
        });
    }

    #[Test]
    #[TestWith([SimpleJob::class, 'cloudtasks'])]
    #[TestWith([SimpleJob::class, 'appengine'])]
    #[TestWith([EncryptedJob::class, 'cloudtasks'])]
    #[TestWith([EncryptedJob::class, 'appengine'])]
    public function it_reads_the_custom_connection_with_custom_queue(string $job, string $taskType)
    {
        // Arrange
        $this->withTaskType($taskType);

        // Act
        $this->dispatch(
            (new $job)
                ->onConnection('my-other-cloudtasks-connection')
                ->onQueue('custom-barbequeue')
        )->run();

        // Assert
        Event::assertDispatched(function (TaskIncoming $event) {
            return $event->task->connection() === 'my-other-cloudtasks-connection'
                && $event->task->queue() === 'custom-barbequeue';
        });
    }

    #[Test]
    public function it_can_convert_the_incoming_task_to_array()
    {
        // Act
        $incomingTask = IncomingTask::fromJson('{"internal":{"connection":"my-other-cloudtasks-connection","queue":"custom-barbequeue","taskName":"projects/my-test-project/locations/europe-west6/queues/barbequeue/tasks/01HSR4V9QE2F4T0K8RBAYQ88KE-SimpleJob"}}');

        // Act
        $array = $incomingTask->toArray();

        // Assert
        $this->assertIsArray($array);
        $this->assertSame('my-other-cloudtasks-connection', $array['internal']['connection']);
    }

    #[Test]
    public function test_invalid_function()
    {
        // Assert
        $this->expectExceptionMessage('Invalid task payload.');

        // Act
        IncomingTask::fromJson('{ invalid json }');
    }
}
