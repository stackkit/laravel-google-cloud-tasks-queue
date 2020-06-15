<?php

namespace Tests;

use Firebase\JWT\JWT;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Mail;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksException;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;
use Tests\Support\TestMailable;

class TaskHandlerTest extends TestCase
{
    /**
     * @var TaskHandler
     */
    private $handler;

    private $client;

    private $jwt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = \Mockery::mock(CloudTasksClient::class)->makePartial();

        $this->jwt = \Mockery::mock(JWT::class)->makePartial();

        $this->handler = \Mockery::mock(new TaskHandler(
            $this->client,
            request(),
            new Client(),
            $this->jwt
        ))->shouldAllowMockingProtectedMethods();
        $this->app->instance(TaskHandler::class, $this->handler);

        $this->jwt->shouldReceive('decode')->andReturnNull();
        $this->handler->shouldReceive('authorizeRequest')->andReturnNull();
    }

    /** @test */
    public function it_needs_an_authorization_header()
    {
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Missing [Authorization] header');

        request()->headers->add(['X-Cloudtasks-Taskname' => 'test']);
        request()->headers->add(['X-Cloudtasks-Queuename' => 'test']);
        $this->handler->handle();
    }

    /** @test */
    public function it_runs_the_incoming_job()
    {
        Mail::fake();

        request()->headers->add(['X-Cloudtasks-Taskname' => 'test']);
        request()->headers->add(['X-Cloudtasks-Queuename' => 'test']);
        request()->headers->add(['Authorization' => 'Bearer 123']);

        $this->client->shouldReceive('getTask')->andReturnNull();

        $this->handler->handle(json_decode(file_get_contents(__DIR__ . '/Support/test-job-payload.json'), true));

        Mail::assertSent(TestMailable::class);
    }
}
