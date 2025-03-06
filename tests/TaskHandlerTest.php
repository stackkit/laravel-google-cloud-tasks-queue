<?php

declare(strict_types=1);

namespace Tests;

use Override;
use Tests\Support\JobOutput;
use Tests\Support\SimpleJob;
use Tests\Support\FailingJob;
use Tests\Support\EncryptedJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SimpleJobWithTimeout;
use Tests\Support\FailingJobWithMaxTries;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\FailingJobWithNoMaxTries;
use Tests\Support\FailingJobWithRetryUntil;
use Tests\Support\FailingJobWithUnlimitedTries;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Tests\Support\FailingJobWithMaxTriesAndRetryUntil;
use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

class TaskHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CloudTasksApi::fake();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        CloudTasksQueue::forgetWorkerOptionsCallback();
    }

    #[Test]
    public function it_can_run_a_task()
    {
        // Arrange
        Event::fake(JobOutput::class);

        // Act
        $this->dispatch(new SimpleJob)->run();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');
    }

    #[Test]
    public function it_can_run_a_task_using_the_task_connection()
    {
        // Arrange

        Event::fake(JobOutput::class);
        $this->app['config']->set('queue.default', 'non-existing-connection');

        // Act
        $job = new SimpleJob;
        $job->connection = 'my-cloudtasks-connection';
        $this->dispatch($job)->run();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');
    }

    #[Test]
    public function after_max_attempts_it_will_log_to_failed_table()
    {
        // Arrange
        $job = $this->dispatch(new FailingJobWithMaxTries);

        // Act & Assert
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $job->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob->run();
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    #[Test]
    public function uses_worker_options_callback_and_after_max_attempts_it_will_log_to_failed_table()
    {
        // Arrange
        CloudTasksQueue::configureWorkerOptionsUsing(function (IncomingTask $task) {
            $queueTries = [
                'high' => 5,
                'low' => 1,
            ];

            return new WorkerOptions(maxTries: $queueTries[$task->queue()] ?? 1);
        });

        $job = $this->dispatch(tap(new FailingJobWithNoMaxTries, fn ($job) => $job->queue = 'high'));

        // Act & Assert
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $job->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);
        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);
        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob->run();
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    #[Test]
    public function after_max_attempts_it_will_no_longer_execute_the_task()
    {
        // Arrange
        Event::fake([JobOutput::class]);
        $job = $this->dispatch(new FailingJob);

        // Act & Assert
        $releasedJob = $job->runAndGetReleasedJob();
        Event::assertDispatched(JobOutput::class, 1);
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $releasedJob->runAndGetReleasedJob();
        Event::assertDispatched(JobOutput::class, 2);
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob->run();
        Event::assertDispatched(JobOutput::class, 4);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    #[Test]
    #[TestWith([['now' => '2020-01-01 00:00:00', 'try_at' => '2020-01-01 00:00:00', 'should_fail' => false]])]
    #[TestWith([['now' => '2020-01-01 00:00:00', 'try_at' => '2020-01-01 00:04:59', 'should_fail' => false]])]
    #[TestWith([['now' => '2020-01-01 00:00:00', 'try_at' => '2020-01-01 00:05:00', 'should_fail' => true]])]
    public function after_max_retry_until_it_will_log_to_failed_table(array $args)
    {
        // Arrange
        $this->travelTo($args['now']);

        $job = $this->dispatch(new FailingJobWithRetryUntil);

        // Act
        $releasedJob = $job->runAndGetReleasedJob();

        // Assert
        $this->assertDatabaseCount('failed_jobs', 0);

        // Act
        $this->travelTo($args['try_at']);
        $releasedJob->run();

        // Assert
        $this->assertDatabaseCount('failed_jobs', $args['should_fail'] ? 1 : 0);
    }

    #[Test]
    public function test_unlimited_max_attempts()
    {
        // Assert
        Event::fake(JobOutput::class);

        // Act
        $job = $this->dispatch(new FailingJobWithUnlimitedTries);

        foreach (range(0, 50) as $attempt) {
            usleep(1000);
            $job = $job->runAndGetReleasedJob();
        }

        Event::assertDispatched(JobOutput::class, 51);
    }

    #[Test]
    public function test_max_attempts_in_combination_with_retry_until()
    {
        // Arrange
        $this->travelTo('2020-01-01 00:00:00');

        $job = $this->dispatch(new FailingJobWithMaxTriesAndRetryUntil);

        // When retryUntil is specified, the maxAttempts is ignored.

        // Act & Assert

        // The max attempts is 3, but the retryUntil is set to 5 minutes from now.
        // So when we attempt the job 4 times, it should still not fail.
        $job = $job
            ->runAndGetReleasedJob()
            ->runAndGetReleasedJob()
            ->runAndGetReleasedJob()
            ->runAndGetReleasedJob();

        $this->assertDatabaseCount('failed_jobs', 0);

        // Now we travel to 5 minutes from now, and the job should fail.
        $this->travelTo('2020-01-01 00:05:00');
        $job->run();
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    #[Test]
    public function it_can_handle_encrypted_jobs()
    {
        // Arrange
        Event::fake(JobOutput::class);

        // Act
        $job = $this->dispatch(new EncryptedJob);
        $job->run();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'EncryptedJob:success');
    }

    #[Test]
    public function failing_jobs_are_released()
    {
        // Arrange
        Event::fake(JobReleasedAfterException::class);

        // Act
        $job = $this->dispatch(new FailingJob);

        CloudTasksApi::assertDeletedTaskCount(0);
        CloudTasksApi::assertCreatedTaskCount(1);
        CloudTasksApi::assertTaskNotDeleted($job->task->getName());

        $job->run();

        CloudTasksApi::assertCreatedTaskCount(2);
        Event::assertDispatched(JobReleasedAfterException::class, function ($event) {
            return $event->job->attempts() === 1;
        });
    }

    #[Test]
    public function attempts_are_tracked_internally()
    {
        // Arrange
        Event::fake(JobReleasedAfterException::class);

        // Act & Assert
        $job = $this->dispatch(new FailingJob);

        $released = $job->runAndGetReleasedJob();

        Event::assertDispatched(JobReleasedAfterException::class, function ($event) use (&$releasedJob) {
            $releasedJob = $event->job->getRawBody();

            return $event->job->attempts() === 1;
        });

        $released->run();

        Event::assertDispatched(JobReleasedAfterException::class, function ($event) {
            return $event->job->attempts() === 2;
        });
    }

    #[Test]
    public function retried_jobs_get_a_new_name()
    {
        // Arrange
        Event::fake(JobReleasedAfterException::class);
        CloudTasksApi::fake();

        // Act & Assert
        $this->assertCount(0, $this->createdTasks);
        $this->dispatch(new FailingJob)->runAndGetReleasedJob();
        $this->assertCount(2, $this->createdTasks);
        $this->assertNotEquals($this->createdTasks[0]->getName(), $this->createdTasks[1]->getName());
    }

    #[Test]
    public function test_job_timeout()
    {
        // Arrange
        Event::fake(JobReleasedAfterException::class);

        // Act
        $this->dispatch(new SimpleJobWithTimeout)->run();

        // Assert
        Event::assertDispatched(JobReleasedAfterException::class);
    }
}
