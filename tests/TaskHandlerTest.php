<?php

namespace Tests;

use Firebase\JWT\ExpiredException;
use Google\Cloud\Tasks\V2\RetryConfig;
use Google\Protobuf\Duration;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksException;
use Stackkit\LaravelGoogleCloudTasksQueue\LogFake;
use Stackkit\LaravelGoogleCloudTasksQueue\OpenIdVerificator;
use Stackkit\LaravelGoogleCloudTasksQueue\StackkitCloudTask;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;
use Tests\Support\EncryptedJob;
use Tests\Support\FailingJob;
use Tests\Support\SimpleJob;
use UnexpectedValueException;

class TaskHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CloudTasksApi::fake();
    }

    /**
     * @test
     * @testWith [true]
     *           [false]
     */
    public function it_returns_responses_for_empty_payloads($debug)
    {
        // Arrange
        config()->set('app.debug', $debug);

        // Act
        $response = $this->postJson(action([TaskHandler::class, 'handle']));

        // Assert
        if ($debug) {
            $response->assertJsonValidationErrors('task');
        } else {
            $response->assertNotFound();
        }
    }

    /**
     * @test
     * @testWith [true]
     *           [false]
     */
    public function it_returns_responses_for_invalid_json($debug)
    {
        // Arrange
        config()->set('app.debug', $debug);

        // Act
        $response = $this->call(
            'POST',
            action([TaskHandler::class, 'handle']),
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
            'test',
        );

        // Assert
        if ($debug) {
            $response->assertJsonValidationErrors('task');
            $this->assertEquals('The json must be a valid JSON string.', $response->json('errors.json.0'));
        } else {
            $response->assertNotFound();
        }
    }

    /**
     * @test
     * @testWith ["{\"invalid\": \"data\"}", "The task.data field is required."]
     *           ["{\"data\": \"\"}", "The task.data field is required."]
     *           ["{\"data\": \"test\"}", "The task.data must be an array."]
     */
    public function it_returns_responses_for_invalid_payloads(string $payload, string $expectedMessage)
    {
        // Arrange

        // Act
        $response = $this->call(
            'POST',
            action([TaskHandler::class, 'handle']),
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload,
        );

        // Assert
        $response->assertJsonValidationErrors('task.data');
        $this->assertEquals($expectedMessage, $response->json(['errors', 'task.data', 0]));
    }

    /**
     * @test
     * @testWith [true]
     *           [false]
     */
    public function it_validates_headers(bool $withHeaders)
    {
        // Arrange
        $this->withExceptionHandling();

        // Act
        $response = $this->postJson(
            action([TaskHandler::class, 'handle']),
            [],
            $withHeaders
                ? [
                    'X-CloudTasks-Taskname' => 'MyTask',
                ] : []
        );

        // Assert
        if ($withHeaders) {
            $response->assertJsonMissingValidationErrors('name_header');
        } else {
            $response->assertJsonValidationErrors('name_header');
        }
    }

    /**
     * @test
     */
    public function the_task_handler_needs_an_open_id_token()
    {
        // Assert
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Missing [Authorization] header');

        // Act
        $this->dispatch(new SimpleJob())->runWithoutExceptionHandler();
    }

    /**
     * @test
     */
    public function the_task_handler_throws_an_exception_if_the_id_token_is_invalid()
    {
        // Arrange
        request()->headers->set('Authorization', 'Bearer my-invalid-token');

        // Assert
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Wrong number of segments');

        // Act
        $this->dispatch(new SimpleJob())->runWithoutExceptionHandler();
    }

    /**
     * @test
     */
    public function it_validates_the_token_expiration()
    {
        // Arrange
        OpenIdVerificator::fake();
        $this->addIdTokenToHeader(function (array $base) {
            return ['exp' => time() - 5] + $base;
        });

        // Assert
        $this->expectException(ExpiredException::class);
        $this->expectExceptionMessage('Expired token');

        // Act
        $this->dispatch(new SimpleJob())->runWithoutExceptionHandler();
    }

    /**
     * @test
     */
    public function it_validates_the_token_aud()
    {
        // Arrange
        OpenIdVerificator::fake();
        $this->addIdTokenToHeader(function (array $base) {
            return ['aud' => 'invalid-aud'] + $base;
        });

        // Assert
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Audience does not match');

        // Act
        $this->dispatch(new SimpleJob())->runWithoutExceptionHandler();
    }

    /**
     * @test
     */
    public function it_can_run_a_task()
    {
        // Arrange
        OpenIdVerificator::fake();
        Log::swap(new LogFake());
        Event::fake([JobProcessing::class, JobProcessed::class]);

        // Act
        $this->dispatch(new SimpleJob())->runWithoutExceptionHandler();

        // Assert
        Log::assertLogged('SimpleJob:success');
    }

    /**
     * @test
     */
    public function after_max_attempts_it_will_log_to_failed_table()
    {
        // Arrange
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxAttempts(3)
        );
        $job = $this->dispatch(new FailingJob());

        // Act & Assert
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $job->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob->run();
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * @test
     */
    public function after_max_attempts_it_will_delete_the_task()
    {
        // Arrange
        OpenIdVerificator::fake();

        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxAttempts(3)
        );

        $job = $this->dispatch(new FailingJob());

        // Act & Assert
        $releasedJob = $job->runAndGetReleasedJob();
        CloudTasksApi::assertDeletedTaskCount(1);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $releasedJob->runAndGetReleasedJob();
        CloudTasksApi::assertDeletedTaskCount(2);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob->run();
        CloudTasksApi::assertDeletedTaskCount(3);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * @test
     */
    public function after_max_retry_until_it_will_log_to_failed_table_and_delete_the_task()
    {
        // Arrange
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxRetryDuration(new Duration(['seconds' => 30]))
        );
        CloudTasksApi::partialMock()->shouldReceive('getRetryUntilTimestamp')->andReturn(1);
        $job = $this->dispatch(new FailingJob());

        // Act
        $releasedJob = $job->runAndGetReleasedJob();

        // Assert
        CloudTasksApi::assertDeletedTaskCount(1);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 0);

        // Act
        CloudTasksApi::partialMock()->shouldReceive('getRetryUntilTimestamp')->andReturn(1);
        $releasedJob->run();

        // Assert
        CloudTasksApi::assertDeletedTaskCount(2);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * @test
     */
    public function test_unlimited_max_attempts()
    {
        // Arrange
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            // -1 is a valid option in Cloud Tasks to indicate there is no max.
            (new RetryConfig())->setMaxAttempts(-1)
        );

        // Act
        $job = $this->dispatch(new FailingJob());
        foreach (range(1, 50) as $attempt) {
            $job->run();
            CloudTasksApi::assertDeletedTaskCount($attempt);
            CloudTasksApi::assertTaskDeleted($job->task->getName());
            $this->assertDatabaseCount('failed_jobs', 0);
        }
    }

    /**
     * @test
     */
    public function test_max_attempts_in_combination_with_retry_until()
    {
        // Laravel 5, 6, 7: check both max_attempts and retry_until before failing a job.
        // Laravel 8+: if retry_until, only check that

        // Arrange
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())
                ->setMaxAttempts(3)
                ->setMaxRetryDuration(new Duration(['seconds' => 3]))
        );
        CloudTasksApi::partialMock()->shouldReceive('getRetryUntilTimestamp')->andReturn(time() + 10)->byDefault();

        $job = $this->dispatch(new FailingJob());

        // Act & Assert
        $releasedJob = $job->runAndGetReleasedJob();
        $releasedJob = $releasedJob->runAndGetReleasedJob();

        # After 2 attempts both Laravel versions should report the same: 2 errors and 0 failures.
        $task = StackkitCloudTask::whereTaskUuid($job->payloadAsArray('uuid'))->firstOrFail();
        $this->assertEquals(2, $task->getNumberOfAttempts());
        $this->assertEquals('error', $task->status);

        $releasedJob->run();

        # Max attempts was reached
        # Laravel 5, 6, 7: fail because max attempts was reached
        # Laravel 8+: don't fail because retryUntil has not yet passed.

        if (version_compare(app()->version(), '8.0.0', '<')) {
            $this->assertEquals('failed', $task->fresh()->status);
            return;
        } else {
            $this->assertEquals('error', $task->fresh()->status);
        }

        CloudTasksApi::shouldReceive('getRetryUntilTimestamp')->andReturn(time() - 1);
        $releasedJob->run();

        $this->assertEquals('failed', $task->fresh()->status);
    }

    /**
     * @test
     */
    public function it_can_handle_encrypted_jobs()
    {
        if (version_compare(app()->version(), '8.0.0', '<')) {
            $this->markTestSkipped('Not supported by Laravel 7.x and below.');
        }

        // Arrange
        OpenIdVerificator::fake();
        Log::swap(new LogFake());

        // Act
        $job = $this->dispatch(new EncryptedJob());
        $job->run();

        // Assert
        $this->assertStringContainsString(
            'O:26:"Tests\Support\EncryptedJob"',
            decrypt($job->payloadAsArray('data.command')),
        );

        Log::assertLogged('EncryptedJob:success');
    }

    /**
     * @test
     */
    public function failing_jobs_are_released()
    {
        // Arrange
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxAttempts(3)
        );
        Event::fake($this->getJobReleasedAfterExceptionEvent());

        // Act
        $job = $this->dispatch(new FailingJob());

        CloudTasksApi::assertDeletedTaskCount(0);
        CloudTasksApi::assertCreatedTaskCount(1);
        CloudTasksApi::assertTaskNotDeleted($job->task->getName());

        $job->run();

        CloudTasksApi::assertDeletedTaskCount(1);
        CloudTasksApi::assertCreatedTaskCount(2);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        Event::assertDispatched($this->getJobReleasedAfterExceptionEvent(), function ($event) {
            return $event->job->attempts() === 1;
        });
    }

    /**
     * @test
     */
    public function attempts_are_tracked_internally()
    {
        // Arrange
        CloudTasksApi::fake();
        OpenIdVerificator::fake();
        Event::fake($this->getJobReleasedAfterExceptionEvent());

        // Act & Assert
        $job = $this->dispatch(new FailingJob());
        $job->run();
        $releasedJob = null;

        Event::assertDispatched($this->getJobReleasedAfterExceptionEvent(), function ($event) use (&$releasedJob) {
            $releasedJob = $event->job->getRawBody();
            return $event->job->attempts() === 1;
        });

        $this->runFromPayload($releasedJob);

        Event::assertDispatched($this->getJobReleasedAfterExceptionEvent(), function ($event) {
            return $event->job->attempts() === 2;
        });
    }
}
