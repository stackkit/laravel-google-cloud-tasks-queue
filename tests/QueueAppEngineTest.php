<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\SimpleJob;
use Google\Cloud\Tasks\V2\Task;
use PHPUnit\Framework\Attributes\Test;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;

class QueueAppEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withTaskType('appengine');
    }

    #[Test]
    public function an_app_engine_http_request_with_the_handler_url_is_made()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getAppEngineHttpRequest()->getRelativeUri() === '/handle-task';
        });
    }

    #[Test]
    public function it_routes_to_the_service()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getAppEngineHttpRequest()->getAppEngineRouting()->getService() === 'api';
        });
    }

    #[Test]
    public function it_contains_the_payload()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch($job = new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) use ($job): bool {
            $decoded = json_decode($task->getAppEngineHttpRequest()->getBody(), true);

            return $decoded['displayName'] === SimpleJob::class
                && $decoded['job'] === 'Illuminate\Queue\CallQueuedHandler@call'
                && $decoded['data']['command'] === serialize($job);
        });
    }
}
