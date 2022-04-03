<?php

declare(strict_types=1);

namespace Tests;

use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\Task;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
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
            return $task->getHttpRequest()->getUrl() === 'http://docker.for.mac.localhost:8080';
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
            $command = unserialize($decoded['data']['command']);

            return $decoded['displayName'] === SimpleJob::class
                && $command->queue === null
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/barbequeue';
        });

        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = unserialize($decoded['data']['command']);

            return $decoded['displayName'] === FailingJob::class
                && $command->queue === 'my-special-queue'
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/my-special-queue';
        });
    }
}
