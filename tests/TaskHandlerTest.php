<?php

namespace Tests;

use Google\Cloud\Tasks\V2\CloudTasksClient;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = \Mockery::mock(CloudTasksClient::class)->makePartial();
        $this->handler = new TaskHandler(
            $this->client,
            request()
        );
    }

    /** @test */
    public function it_needs_a_task_name_header()
    {
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Missing [X-Cloudtasks-Taskname] header');

        $this->handler->handle();
    }

    /** @test */
    public function it_needs_a_queue_name_header()
    {
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Missing [X-Cloudtasks-Queuename] header');

        request()->headers->add(['X-Cloudtasks-Taskname' => 'test']);
        $this->handler->handle();
    }

    /** @test */
    public function it_needs_a_stackkit_auth_token_header()
    {
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Missing [X-Stackkit-Auth-Token] header');

        request()->headers->add(['X-Cloudtasks-Taskname' => 'test']);
        request()->headers->add(['X-Cloudtasks-Queuename' => 'test']);
        $this->handler->handle();
    }

    /** @test */
    public function it_will_check_if_the_incoming_task_exists()
    {
        request()->headers->add(['X-Cloudtasks-Taskname' => 'test']);
        request()->headers->add(['X-Cloudtasks-Queuename' => 'test']);
        request()->headers->add(['X-Stackkit-Auth-Token' => encrypt('test')]);

        Mail::fake();

        $this->client
            ->shouldReceive('getTask')
            ->once()
            ->with('projects/test-project/locations/europe-west6/queues/test/tasks/test')
            ->andReturnNull();

        $this->handler->handle(json_decode(file_get_contents(__DIR__ . '/Support/test-job-payload.json'), true));
    }

    /** @test */
    public function it_will_check_the_auth_token()
    {
        request()->headers->add(['X-Cloudtasks-Taskname' => 'test']);
        request()->headers->add(['X-Cloudtasks-Queuename' => 'test']);
        request()->headers->add(['X-Stackkit-Auth-Token' => encrypt('does not match the task name')]);

        $this->client->shouldReceive('getTask')->andReturnNull();

        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Auth token is not valid');

        $this->handler->handle(json_decode(file_get_contents(__DIR__ . '/Support/test-job-payload.json'), true));
    }

    /** @test */
    public function it_runs_the_incoming_job()
    {
        Mail::fake();

        request()->headers->add(['X-Cloudtasks-Taskname' => 'test']);
        request()->headers->add(['X-Cloudtasks-Queuename' => 'test']);
        request()->headers->add(['X-Stackkit-Auth-Token' => encrypt('test')]);

        $this->client->shouldReceive('getTask')->andReturnNull();

        $this->handler->handle(json_decode(file_get_contents(__DIR__ . '/Support/test-job-payload.json'), true));

        Mail::assertSent(TestMailable::class);
    }
}
