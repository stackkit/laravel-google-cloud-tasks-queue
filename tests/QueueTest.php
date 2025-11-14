<?php

declare(strict_types=1);

namespace Tests;

use Override;
use Tests\Support\User;
use Tests\Support\UserJob;
use Illuminate\Support\Str;
use Tests\Support\JobOutput;
use Tests\Support\SimpleJob;
use Tests\Support\FailingJob;
use Illuminate\Support\Carbon;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Google\Cloud\Tasks\V2\HttpMethod;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobQueued;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Queue\CallQueuedClosure;
use Tests\Support\SimpleJobWithTimeout;
use Tests\Support\JobThatWillBeReleased;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Tests\Support\SimpleJobWithDelayProperty;
use Tests\Support\FailingJobWithExponentialBackoff;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;

class QueueTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        CloudTasksQueue::forgetHandlerUrlCallback();
        CloudTasksQueue::forgetTaskHeadersCallback();
    }

    #[Test]
    public function a_http_request_with_the_handler_url_is_made()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getUrl() === 'https://docker.for.mac.localhost:8080/handle-task';
        });
    }

    #[Test]
    public function it_posts_to_the_handler()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getHttpMethod() === HttpMethod::POST;
        });
    }

    #[Test]
    public function it_posts_to_the_configured_handler_url()
    {
        // Arrange
        $this->setConfigValue('handler', 'https://docker.for.mac.localhost:8081');
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getUrl() === 'https://docker.for.mac.localhost:8081/handle-task';
        });
    }

    #[Test]
    public function it_posts_to_the_callback_handler_url()
    {
        // Arrange
        $this->setConfigValue('handler', 'https://docker.for.mac.localhost:8081');
        CloudTasksApi::fake();
        CloudTasksQueue::configureHandlerUrlUsing(static fn (SimpleJob $job) => 'https://example.com/api/my-custom-route?job='.$job->id);

        // Act
        $job = new SimpleJob;
        $job->id = 1;
        $this->dispatch($job);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getUrl() === 'https://example.com/api/my-custom-route?job=1';
        });
    }

    #[Test]
    public function it_posts_the_serialized_job_payload_to_the_handler()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch($job = new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) use ($job): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);

            return $decoded['displayName'] === SimpleJob::class
                && $decoded['job'] === 'Illuminate\Queue\CallQueuedHandler@call'
                && $decoded['data']['command'] === serialize($job);
        });
    }

    #[Test]
    public function it_will_set_the_scheduled_time_when_dispatching_later()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $inFiveMinutes = now()->addMinutes(5);
        $this->dispatch((new SimpleJob)->delay($inFiveMinutes));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) use ($inFiveMinutes): bool {
            return $task->getScheduleTime()->getSeconds() === $inFiveMinutes->timestamp;
        });
    }

    #[Test]
    public function it_posts_the_task_the_correct_queue()
    {
        // Arrange
        CloudTasksApi::fake();

        $closure = fn () => 'closure job';
        $closureDisplayName = CallQueuedClosure::create($closure)->displayName();

        // Act
        $this->dispatch((new SimpleJob));
        $this->dispatch((new FailingJob)->onQueue('my-special-queue'));
        $this->dispatch($closure);
        $this->dispatch($closure, 'my-special-queue');

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = IncomingTask::fromJson($task->getHttpRequest()->getBody())->command();

            return $decoded['displayName'] === SimpleJob::class
                && $command['queue'] === 'barbequeue'
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/barbequeue';
        });

        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = IncomingTask::fromJson($task->getHttpRequest()->getBody())->command();

            return $decoded['displayName'] === FailingJob::class
                && $command['queue'] === 'my-special-queue'
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/my-special-queue';
        });

        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName) use ($closureDisplayName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = IncomingTask::fromJson($task->getHttpRequest()->getBody())->command();

            return $decoded['displayName'] === $closureDisplayName
                && $command['queue'] === 'barbequeue'
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/barbequeue';
        });

        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName) use ($closureDisplayName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = IncomingTask::fromJson($task->getHttpRequest()->getBody())->command();

            return $decoded['displayName'] === $closureDisplayName
                && $command['queue'] === 'my-special-queue'
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/my-special-queue';
        });
    }

    #[Test]
    public function it_can_dispatch_after_commit_inline()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake();

        // Act & Assert
        Event::assertNotDispatched(JobQueued::class);
        DB::beginTransaction();
        SimpleJob::dispatch()->afterCommit();
        Event::assertNotDispatched(JobQueued::class);
        while (DB::transactionLevel() !== 0) {
            DB::commit();
        }
        Event::assertDispatched(JobQueued::class, function (JobQueued $event) {
            return $event->job instanceof SimpleJob;
        });
    }

    #[Test]
    public function it_can_dispatch_after_commit_through_config()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake();
        $this->setConfigValue('after_commit', true);

        // Act & Assert
        Event::assertNotDispatched(JobQueued::class);
        DB::beginTransaction();
        SimpleJob::dispatch();
        Event::assertNotDispatched(JobQueued::class);
        while (DB::transactionLevel() !== 0) {
            DB::commit();
        }
        Event::assertDispatched(JobQueued::class, function (JobQueued $event) {
            return $event->job instanceof SimpleJob;
        });
    }

    #[Test]
    public function jobs_can_be_released()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake([
            JobReleasedAfterException::class,
            JobReleased::class,
        ]);

        // Act
        $this->dispatch(new JobThatWillBeReleased)
            ->runAndGetReleasedJob()
            ->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            $body = $task->getHttpRequest()->getBody();
            $decoded = json_decode($body, true);

            return $decoded['data']['commandName'] === 'Tests\\Support\\JobThatWillBeReleased'
                && $decoded['internal']['attempts'] === 1;
        });

        CloudTasksApi::assertTaskCreated(function (Task $task) {
            $body = $task->getHttpRequest()->getBody();
            $decoded = json_decode($body, true);

            return $decoded['data']['commandName'] === 'Tests\\Support\\JobThatWillBeReleased'
                && $decoded['internal']['attempts'] === 2;
        });
    }

    #[Test]
    public function jobs_can_be_released_with_a_delay()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake([
            JobReleasedAfterException::class,
            JobReleased::class,
        ]);
        Carbon::setTestNow(now()->addDay());

        // Act
        $this->dispatch(new JobThatWillBeReleased(15))->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            $body = $task->getHttpRequest()->getBody();
            $decoded = json_decode($body, true);

            $scheduleTime = $task->getScheduleTime() ? $task->getScheduleTime()->getSeconds() : null;

            return $decoded['data']['commandName'] === 'Tests\\Support\\JobThatWillBeReleased'
                && $decoded['internal']['attempts'] === 1
                && $scheduleTime === now()->getTimestamp() + 15;
        });
    }

    #[Test]
    public function test_default_backoff()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobReleasedAfterException::class);

        // Act
        $this->dispatch(new FailingJob)->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return is_null($task->getScheduleTime());
        });
    }

    #[Test]
    public function test_backoff_from_queue_config()
    {
        // Arrange
        Carbon::setTestNow(now()->addDay());
        $this->setConfigValue('backoff', 123);
        CloudTasksApi::fake();
        Event::fake(JobReleasedAfterException::class);

        // Act
        $this->dispatch(new FailingJob)->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getScheduleTime()
                && $task->getScheduleTime()->getSeconds() === now()->getTimestamp() + 123;
        });
    }

    #[Test]
    public function test_backoff_from_job()
    {
        // Arrange
        Carbon::setTestNow(now()->addDay());
        CloudTasksApi::fake();
        Event::fake(JobReleasedAfterException::class);

        // Act
        $failingJob = new FailingJob;
        $failingJob->backoff = 123;
        $this->dispatch($failingJob)->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getScheduleTime()
                && $task->getScheduleTime()->getSeconds() === now()->getTimestamp() + 123;
        });
    }

    #[Test]
    public function test_exponential_backoff_from_job_method()
    {
        // Arrange
        Carbon::setTestNow(now()->addDay());
        CloudTasksApi::fake();

        // Act
        $releasedJob = $this->dispatch(new FailingJobWithExponentialBackoff)
            ->runAndGetReleasedJob();
        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $releasedJob->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getScheduleTime()
                && $task->getScheduleTime()->getSeconds() === now()->getTimestamp() + 50;
        });
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getScheduleTime()
                && $task->getScheduleTime()->getSeconds() === now()->getTimestamp() + 60;
        });
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getScheduleTime()
                && $task->getScheduleTime()->getSeconds() === now()->getTimestamp() + 70;
        });
    }

    #[Test]
    public function test_failing_method_on_job()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobOutput::class);

        // Act
        $this->dispatch(new FailingJob)
            ->runAndGetReleasedJob()
            ->runAndGetReleasedJob()
            ->runAndGetReleasedJob();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'FailingJob:failed');
    }

    #[Test]
    public function test_queue_before_and_after_hooks()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobOutput::class);

        // Act
        Queue::before(function (JobProcessing $event) {
            event(new JobOutput('Queue::before:'.$event->job->payload()['data']['commandName']));
        });
        Queue::after(function (JobProcessed $event) {
            event(new JobOutput('Queue::after:'.$event->job->payload()['data']['commandName']));
        });
        $this->dispatch(new SimpleJob)->run();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'Queue::before:Tests\Support\SimpleJob');
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'Queue::after:Tests\Support\SimpleJob');
    }

    #[Test]
    public function test_queue_looping_hook_not_supported_with_this_package()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobOutput::class);

        // Act
        Queue::looping(function () {
            event(new JobOutput('Queue::looping'));
        });
        $this->dispatch(new SimpleJob)->run();

        // Assert
        Event::assertDispatchedTimes(JobOutput::class, times: 1);
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');
    }

    #[Test]
    public function test_ignoring_jobs_with_deleted_models()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobOutput::class);

        $user1 = User::create([
            'name' => 'John',
            'email' => 'johndoe@example.com',
            'password' => bcrypt('test'),
        ]);

        $user2 = User::create([
            'name' => 'Jane',
            'email' => 'janedoe@example.com',
            'password' => bcrypt('test'),
        ]);

        // Act
        $this->dispatch(new UserJob($user1))->run();

        $job = $this->dispatch(new UserJob($user2));
        $user2->delete();
        $job->run();

        // Act
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'UserJob:John');
        CloudTasksApi::assertTaskNotDeleted($job->task->getName());
    }

    #[Test]
    public function it_adds_a_pre_defined_task_name()
    {
        // Arrange
        CloudTasksApi::fake();
        Str::createUlidsUsingSequence(['01HSR4V9QE2F4T0K8RBAYQ88KE']);

        // Act
        $this->dispatch((new SimpleJob));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getName() === 'projects/my-test-project/locations/europe-west6/queues/barbequeue/tasks/01HSR4V9QE2F4T0K8RBAYQ88KE-SimpleJob';
        });
    }

    #[Test]
    public function headers_can_be_added_to_the_task()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        CloudTasksQueue::setTaskHeadersUsing(static fn () => [
            'X-MyHeader' => 'MyValue',
        ]);

        $this->dispatch((new SimpleJob));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getHeaders()['X-MyHeader'] === 'MyValue';
        });
    }

    #[Test]
    public function headers_can_be_added_to_the_task_with_job_context()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        CloudTasksQueue::setTaskHeadersUsing(static fn (array $payload) => [
            'X-MyHeader' => $payload['displayName'],
        ]);

        $this->dispatch((new SimpleJob));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getHeaders()['X-MyHeader'] === SimpleJob::class;
        });
    }

    #[Test]
    public function batched_jobs_with_custom_queue_are_dispatched_on_the_custom_queue()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(Bus::batch([
            tap(new SimpleJob, function (SimpleJob $job) {
                $job->queue = 'my-queue1';
            }),
            tap(new SimpleJobWithTimeout, function (SimpleJob $job) {
                $job->queue = 'my-queue2';
            }),
        ])->onQueue('my-batch-queue'));

        // Assert
        CloudTasksApi::assertCreatedTaskCount(2);

        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return str_contains($task->getName(), 'SimpleJob')
                && str_contains($task->getName(), 'my-batch-queue');
        });

        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return str_contains($task->getName(), 'SimpleJobWithTimeout')
                && str_contains($task->getName(), 'my-batch-queue');
        });
    }

    #[Test]
    public function it_can_dispatch_closures(): void
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobOutput::class);

        // Act
        $this->dispatch(function () {
            event(new JobOutput('ClosureJob:success'));
        })->run();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'ClosureJob:success');
    }

    #[Test]
    public function task_has_no_dispatch_deadline_by_default(): void
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getDispatchDeadline() === null;
        });
    }

    #[Test]
    public function task_has_no_dispatch_deadline_if_config_is_empty(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('dispatch_deadline', null);

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getDispatchDeadline() === null;
        });
    }

    #[Test]
    public function task_has_configured_dispatch_deadline(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('dispatch_deadline', 1800);

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getDispatchDeadline()->getSeconds() === 1800;
        });
    }

    #[Test]
    public function it_will_set_the_scheduled_time_when_job_has_delay_property(): void
    {
        // Arrange
        CloudTasksApi::fake();
        Carbon::setTestNow(now()->addDay());

        // Act
        $this->dispatch(Bus::batch([
            new SimpleJobWithDelayProperty(500),
        ]));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getScheduleTime()->getSeconds() === now()->addSeconds(500)->timestamp;
        });
    }

    #[Test]
    public function it_will_not_set_scheduled_time_when_job_delay_property_is_null(): void
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(Bus::batch([
            new SimpleJobWithDelayProperty(null),
        ]));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getScheduleTime() === null;
        });
    }
}
