<?php

namespace Tests;

use Carbon\Carbon;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use Grpc\ChannelCredentials;
use Mockery;
use Tests\Support\SimpleJob;

class QueueTest extends TestCase
{
    /**
     * @var HttpRequest $http
     */
    private $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = $this->instance(
            HttpRequest::class,
            Mockery::mock(new HttpRequest)->makePartial()
        );
    }

    /** @test */
    public function a_http_request_with_the_handler_url_is_made()
    {
        SimpleJob::dispatch();

        $this->http
            ->shouldHaveReceived('setUrl')
            ->with('http://docker.for.mac.localhost:8080/handle-task')
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
    public function it_will_set_the_scheduled_time_when_dispatching_later()
    {
        $task = $this->instance(
            Task::class,
            Mockery::mock(new Task)->makePartial()
        );

        $inFiveMinutes = Carbon::now()->addMinutes(5);

        SimpleJob::dispatch()->delay($inFiveMinutes);

        $task->shouldHaveReceived('setScheduleTime')
            ->once()
            ->with(Mockery::on(function (Timestamp $timestamp) use ($inFiveMinutes) {
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
                return $queueName === 'projects/my-test-project/locations/europe-west6/queues/barbequeue';
            });
    }

    /** @test */
    public function it_posts_the_correct_task_the_queue()
    {
        SimpleJob::dispatch();

        $this->client
            ->shouldHaveReceived('createTask')
            ->withArgs(function ($queueName, Task $task) {
                return strpos(
                    $task->getHttpRequest()->getBody(),
                        'SimpleJob'
                ) !== false;
            });
    }
}
