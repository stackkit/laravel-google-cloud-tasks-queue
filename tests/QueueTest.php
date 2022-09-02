<?php

declare(strict_types=1);

namespace Tests;

use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;
use Tests\Support\FailingJob;
use Tests\Support\SimpleJob;

class QueueTest extends TestCase
{
    /**
     * @test
     */
    public function a_http_request_with_the_handler_url_is_made()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getUrl() === 'http://docker.for.mac.localhost:8080/handle-task';
        });
    }

    /**
     * @test
     */
    public function it_posts_to_the_handler()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getHttpMethod() === HttpMethod::POST;
        });
    }

    /**
     * @test
     */
    public function it_posts_to_the_correct_handler_url()
    {
        // Arrange
        $this->setConfigValue('handler', 'http://docker.for.mac.localhost:8081');
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getUrl() === 'http://docker.for.mac.localhost:8081/handle-task';
        });
    }

    /**
     * @test
     */
    public function it_posts_the_serialized_job_payload_to_the_handler()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch($job = new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) use ($job): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);

            return $decoded['displayName'] === SimpleJob::class
                && $decoded['job'] === 'Illuminate\Queue\CallQueuedHandler@call'
                && $decoded['data']['command'] === serialize($job);
        });
    }

    /**
     * @test
     */
    public function it_will_set_the_scheduled_time_when_dispatching_later()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $inFiveMinutes = now()->addMinutes(5);
        $this->dispatch((new SimpleJob())->delay($inFiveMinutes));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) use ($inFiveMinutes): bool {
            return $task->getScheduleTime()->getSeconds() === $inFiveMinutes->timestamp;
        });
    }

    /**
     * @test
     */
    public function test_dispatch_deadline_config()
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('dispatch_deadline', 30);

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->hasDispatchDeadline()
                && $task->getDispatchDeadline()->getSeconds() === 30;
        });
    }

    /**
     * @test
     */
    public function it_posts_the_task_the_correct_queue()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch((new SimpleJob()));
        $this->dispatch((new FailingJob())->onQueue('my-special-queue'));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = TaskHandler::getCommandProperties($decoded['data']['command']);

            return $decoded['displayName'] === SimpleJob::class
                && $command['queue'] === null
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/barbequeue';
        });

        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = TaskHandler::getCommandProperties($decoded['data']['command']);

            return $decoded['displayName'] === FailingJob::class
                && $command['queue'] === 'my-special-queue'
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/my-special-queue';
        });
    }

    /**
     * @test
     */
    public function it_can_dispatch_after_commit()
    {
        if (version_compare(app()->version(), '8.0.0', '<')) {
            $this->markTestSkipped('Not supported by Laravel 7.x and below.');
        }

        // Arrange
        CloudTasksApi::fake();
        Event::fake();

        // Act & Assert
        Event::assertNotDispatched(JobQueued::class);
        DB::beginTransaction();
        SimpleJob::dispatch()->afterCommit();
        Event::assertNotDispatched(JobQueued::class);
        DB::commit();
        Event::assertDispatched(JobQueued::class, function (JobQueued $event) {
            return $event->job instanceof SimpleJob;
        });
    }
}
