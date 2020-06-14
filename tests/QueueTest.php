<?php

namespace Tests;

use Carbon\Carbon;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use Mockery;
use Tests\Support\SimpleJob;

class QueueTest extends TestCase
{
    private $client;
    private $http;
    private $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(CloudTasksClient::class)->makePartial();
        $this->http = Mockery::mock(HttpRequest::class)->makePartial();
        $this->task = Mockery::mock(new Task);

        $this->app->instance(CloudTasksClient::class, $this->client);
        $this->app->instance(HttpRequest::class, $this->http);
        $this->app->instance(Task::class, $this->task);

        // ensure we don't actually call the Google API
        $this->client->shouldReceive('createTask')->andReturnNull();
    }

    /** @test */
    public function a_http_request_with_the_handler_url_is_made()
    {
        SimpleJob::dispatch();

        $this->http
            ->shouldHaveReceived('setUrl')
            ->with('https://localhost/my-handler')
            ->once();
    }

    /** @test */
    public function it_posts_to_the_handler()
    {
        SimpleJob::dispatch();

        $this->http->shouldHaveReceived('setHttpMethod')->with(HttpMethod::POST)->once();
    }

    /** @test */
    public function it_posts_the_serialized_job_payload_to_the_handler()
    {
        $job = new SimpleJob();
        $job->dispatch();

        $this->http->shouldHaveReceived('setBody')->with(Mockery::on(function ($payload) use ($job) {
            $decoded = json_decode($payload, true);

            if ($decoded['displayName'] != 'Tests\Support\SimpleJob') {
                return false;
            }

            if ($decoded['job'] != 'Illuminate\Queue\CallQueuedHandler@call') {
                return false;
            }

            if ($decoded['data']['commandName'] != 'Tests\Support\SimpleJob') {
                return false;
            }

            if ($decoded['data']['command'] != serialize($job)) {
                return false;
            }

            return true;
        }));
    }

    /** @test */
    public function it_creates_a_task_containing_the_http_request()
    {
        $this->task->shouldReceive('setHttpRequest')->once()->with($this->http);

        SimpleJob::dispatch();
    }

    /** @test */
    public function it_will_set_the_scheduled_time_when_dispatching_later()
    {
        $inFiveMinutes = Carbon::now()->addMinutes(5);

        SimpleJob::dispatch()->delay($inFiveMinutes);

        $this->task->shouldHaveReceived('setScheduleTime')->once()->with(Mockery::on(function (Timestamp $timestamp) use ($inFiveMinutes) {
            return $timestamp->getSeconds() === $inFiveMinutes->timestamp;
        }));
    }

    /** @test */
    public function it_posts_the_task_the_correct_queue()
    {
        SimpleJob::dispatch();

        $this->client
            ->shouldHaveReceived('createTask')
            ->withArgs(function ($queueName) {
                return $queueName === 'projects/test-project/locations/europe-west6/queues/test-queue';
            });
    }

    /** @test */
    public function it_posts_the_correct_task_the_queue()
    {
        SimpleJob::dispatch();

        $this->client
            ->shouldHaveReceived('createTask')
            ->withArgs(function ($queueName, $task) {
                return $task === $this->task;
            });
    }
}
