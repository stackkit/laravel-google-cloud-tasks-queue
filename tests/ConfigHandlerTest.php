<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\SimpleJob;
use Google\Cloud\Tasks\V2\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;

class ConfigHandlerTest extends TestCase
{
    #[DataProvider('handlerDataProvider')]
    public function test_it_allows_a_handler_url_to_contain_path(string $handler, string $expectedHandler): void
    {
        CloudTasksApi::fake();

        $this->setConfigValue('handler', $handler);

        $this->dispatch(new SimpleJob);

        CloudTasksApi::assertTaskCreated(function (Task $task) use ($expectedHandler) {
            return $task->getHttpRequest()->getUrl() === $expectedHandler;
        });
    }

    #[Test]
    public function the_handle_route_task_uri_can_be_configured(): void
    {
        CloudTasksApi::fake();

        $this->app['config']->set('cloud-tasks.uri', 'my-custom-route');

        $this->dispatch(new SimpleJob);

        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getHttpRequest()->getUrl() === 'https://docker.for.mac.localhost:8080/my-custom-route';
        });
    }

    #[Test]
    public function the_handle_route_task_uri_in_combination_with_path_can_be_configured(): void
    {
        CloudTasksApi::fake();

        $this->setConfigValue('handler', 'https://example.com/api');
        $this->app['config']->set('cloud-tasks.uri', 'my-custom-route');

        $this->dispatch(new SimpleJob);

        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getHttpRequest()->getUrl() === 'https://example.com/api/my-custom-route';
        });
    }

    public static function handlerDataProvider(): array
    {
        return [
            ['https://example.com', 'https://example.com/handle-task'],
            ['https://example.com/my/path', 'https://example.com/my/path/handle-task'],
            ['https://example.com/trailing/slashes//', 'https://example.com/trailing/slashes/handle-task'],
            ['https://example.com/handle-task', 'https://example.com/handle-task'],
            ['https://example.com/handle-task/', 'https://example.com/handle-task'],
        ];
    }
}
