<?php

declare(strict_types=1);

namespace Tests;

use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\JobReleased;
use Tests\Support\FailingJob;
use Tests\Support\FailingJobWithExponentialBackoff;
use Tests\Support\JobOutput;
use Tests\Support\JobThatWillBeReleased;
use Tests\Support\SimpleJob;
use Tests\Support\User;
use Tests\Support\UserJob;

class QueueTest extends TestCase
{
    /**
     * @test
     */
    public function a_http_request_with_the_handler_url_is_made()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getUrl() === 'https://docker.for.mac.localhost:8080/handle-task';
        });
    }

    /**
     * @test
     */
    public function it_posts_to_the_handler()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getHttpMethod() === HttpMethod::POST;
        });
    }

    /**
     * @test
     */
    public function it_posts_to_the_correct_handler_url()
    {
        // Arrange
        $this->setConfigValue('handler', 'https://docker.for.mac.localhost:8081');
        CloudTasksApi::fake();

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getUrl() === 'https://docker.for.mac.localhost:8081/handle-task';
        });
    }

    /**
     * @test
     */
    public function it_posts_the_serialized_job_payload_to_the_handler()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch($job = new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) use ($job): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);

            return $decoded['displayName'] === SimpleJob::class
                && $decoded['job'] === 'Illuminate\Queue\CallQueuedHandler@call'
                && $decoded['data']['command'] === serialize($job);
        });
    }

    /**
     * @test
     */
    public function it_will_set_the_scheduled_time_when_dispatching_later()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $inFiveMinutes = now()->addMinutes(5);
        $this->dispatch((new SimpleJob())->delay($inFiveMinutes));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) use ($inFiveMinutes): bool {
            return $task->getScheduleTime()->getSeconds() === $inFiveMinutes->timestamp;
        });
    }

    /**
     * @test
     */
    public function test_dispatch_deadline_config()
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('dispatch_deadline', 30);

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->hasDispatchDeadline()
                && $task->getDispatchDeadline()->getSeconds() === 30;
        });
    }

    /**
     * @test
     */
    public function it_posts_the_task_the_correct_queue()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch((new SimpleJob()));
        $this->dispatch((new FailingJob())->onQueue('my-special-queue'));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = $this->getCommandProperties($decoded['data']['command']);

            return $decoded['displayName'] === SimpleJob::class
                && ($command['queue'] ?? null) === null
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/barbequeue';
        });

        CloudTasksApi::assertTaskCreated(function (Task $task, string $queueName): bool {
            $decoded = json_decode($task->getHttpRequest()->getBody(), true);
            $command = $this->getCommandProperties($decoded['data']['command']);

            return $decoded['displayName'] === FailingJob::class
                && $command['queue'] === 'my-special-queue'
                && $queueName === 'projects/my-test-project/locations/europe-west6/queues/my-special-queue';
        });
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function jobs_can_be_released()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake([
            JobReleasedAfterException::class,
            JobReleased::class,
        ]);

        // Act
        $this->dispatch(new JobThatWillBeReleased())->run();

        // Assert
        Event::assertNotDispatched(JobReleasedAfterException::class);
        CloudTasksApi::assertDeletedTaskCount(0); // it returned 200 OK so we dont delete it, but Google does
        $releasedJob = null;
        Event::assertDispatched(JobReleased::class, function (JobReleased $event) use (&$releasedJob) {
            $releasedJob = $event->job;

            return true;
        });
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            $body = $task->getHttpRequest()->getBody();
            $decoded = json_decode($body, true);

            return $decoded['data']['commandName'] === 'Tests\\Support\\JobThatWillBeReleased'
                && $decoded['internal']['attempts'] === 1;
        });

        $this->runFromPayload($releasedJob->getRawBody());

        CloudTasksApi::assertDeletedTaskCount(0);
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            $body = $task->getHttpRequest()->getBody();
            $decoded = json_decode($body, true);

            return $decoded['data']['commandName'] === 'Tests\\Support\\JobThatWillBeReleased'
                && $decoded['internal']['attempts'] === 2;
        });
    }

    /**
     * @test
     */
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

    /** @test */
    public function test_default_backoff()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobReleasedAfterException::class);

        // Act
        $this->dispatch(new FailingJob())->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return is_null($task->getScheduleTime());
        });
    }

    /** @test */
    public function test_backoff_from_queue_config()
    {
        // Arrange
        Carbon::setTestNow(now()->addDay());
        $this->setConfigValue('backoff', 123);
        CloudTasksApi::fake();
        Event::fake(JobReleasedAfterException::class);

        // Act
        $this->dispatch(new FailingJob())->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getScheduleTime()
                && $task->getScheduleTime()->getSeconds() === now()->getTimestamp() + 123;
        });
    }

    /** @test */
    public function test_backoff_from_job()
    {
        // Arrange
        Carbon::setTestNow(now()->addDay());
        CloudTasksApi::fake();
        Event::fake(JobReleasedAfterException::class);

        // Act
        $failingJob = new FailingJob();
        $failingJob->backoff = 123;
        $this->dispatch($failingJob)->run();

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task) {
            return $task->getScheduleTime()
                && $task->getScheduleTime()->getSeconds() === now()->getTimestamp() + 123;
        });
    }

    /** @test */
    public function test_exponential_backoff_from_job_method()
    {
        // Arrange
        Carbon::setTestNow(now()->addDay());
        CloudTasksApi::fake();

        // Act
        $releasedJob = $this->dispatch(new FailingJobWithExponentialBackoff())
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

    /** @test */
    public function test_failing_method_on_job()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobOutput::class);

        // Act
        $this->dispatch(new FailingJob())
            ->runAndGetReleasedJob()
            ->runAndGetReleasedJob()
            ->runAndGetReleasedJob();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'FailingJob:failed');
    }

    /** @test */
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
        $this->dispatch(new SimpleJob())->run();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'Queue::before:Tests\Support\SimpleJob');
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'Queue::after:Tests\Support\SimpleJob');
    }

    /** @test */
    public function test_queue_looping_hook_not_supported_with_this_package()
    {
        // Arrange
        CloudTasksApi::fake();
        Event::fake(JobOutput::class);

        // Act
        Queue::looping(function () {
            event(new JobOutput('Queue::looping'));
        });
        $this->dispatch(new SimpleJob())->run();

        // Assert
        Event::assertDispatchedTimes(JobOutput::class, times: 1);
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');
    }

    /** @test */
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
        $this->dispatch(new UserJob($user1))->runWithoutExceptionHandler();

        $job = $this->dispatch(new UserJob($user2));
        $user2->delete();
        $job->runWithoutExceptionHandler();

        // Act
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'UserJob:John');
        CloudTasksApi::assertTaskNotDeleted($job->task->getName());
    }

    /**
     * @test
     */
    public function it_adds_a_task_name_based_on_the_display_name()
    {
        // Arrange
        CloudTasksApi::fake();

        // Act
        $this->dispatch((new SimpleJob()));

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return str_starts_with(
                $task->getName(),
                'projects/my-test-project/locations/europe-west6/queues/barbequeue/tasks/Tests-Support-SimpleJob'
            );
        });
    }
}
