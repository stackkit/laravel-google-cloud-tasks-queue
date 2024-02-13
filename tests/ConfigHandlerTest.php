<?php

namespace Tests;

use Stackkit\LaravelGoogleCloudTasksQueue\Config;

class ConfigHandlerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider handlerDataProvider
     */
    public function test_it_allows_a_handler_url_to_contain_path(string $handler, string $expectedHandler): void
    {
        self::assertSame($expectedHandler, Config::getHandler($handler));
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
