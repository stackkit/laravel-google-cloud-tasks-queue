<?php

namespace Tests;

use Illuminate\Support\Facades\Mail;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;
use Tests\Support\TestMailable;

class TaskHandlerTest extends TestCase
{
    /** @test */
    public function it_runs_the_incoming_job()
    {
        Mail::fake();

        $handler = new TaskHandler();

        $handler->handle(json_decode(file_get_contents(__DIR__ . '/Support/test-job-payload.json'), true));

        Mail::assertSent(TestMailable::class);
    }
}
