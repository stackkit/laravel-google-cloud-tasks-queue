<?php

declare(strict_types=1);

namespace Tests;

use Google\Cloud\Tasks\V2\Task;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Tests\Support\SimpleJob;

class ConfigHandlerTest extends TestCase
{
    /**
     * @dataProvider handlerDataProvider
     */
    public function test_it_allows_a_handler_url_to_contain_path(string $handler, string $expectedHandler): void
    {
        CloudTasksApi::fake();

        $this->setConfigValue('handler', $handler);

        $this->dispatch(new SimpleJob());

        CloudTasksApi::assertTaskCreated(function (Task $task) use ($expectedHandler) {
            return $task->getHttpRequest()->getUrl() === $expectedHandler;
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
