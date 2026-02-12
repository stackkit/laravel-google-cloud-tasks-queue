<?php

declare(strict_types=1);

namespace Tests;

use Override;
use Tests\Support\JobOutput;
use Tests\Support\SimpleJob;
use Tests\Support\FailingJob;
use Google\Cloud\Tasks\V2\Task;
use Tests\Support\EncryptedJob;
use Illuminate\Queue\WorkerOptions;
use Google\Cloud\Tasks\V2\HttpMethod;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

class CloudRunJobTest extends TestCase
{
    #[Override]
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

        // Clean up env vars
        putenv('CLOUD_TASKS_PAYLOAD');
        putenv('CLOUD_TASKS_TASK_NAME');
        putenv('CLOUD_TASKS_PAYLOAD_PATH');
    }

    /**
     * Create a base64-encoded job payload for testing.
     */
    private function createPayload(object $job): string
    {
        // Dispatch the job to get the payload format
        $payload = null;

        Event::listen(\Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated::class, function ($event) use (&$payload) {
            $request = $event->task->getHttpRequest() ?? $event->task->getAppEngineHttpRequest();
            $payload = $request->getBody();
        });

        dispatch($job);

        return base64_encode((string) $payload);
    }

    /**
     * Set environment variables for testing the command.
     */
    private function setEnvVars(string $payload, string $taskName): void
    {
        putenv('CLOUD_TASKS_PAYLOAD='.$payload);
        putenv('CLOUD_TASKS_TASK_NAME='.$taskName);
    }

    // ========================================
    // Command Execution Tests
    // ========================================

    #[Test]
    public function it_can_run_a_job_via_the_command(): void
    {
        // Arrange
        Event::fake(JobOutput::class);
        $payload = $this->createPayload(new SimpleJob);
        $this->setEnvVars($payload, 'test-task-name');

        // Act
        $this->artisan('cloud-tasks:work-job')->assertSuccessful();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');
    }

    #[Test]
    public function it_extracts_connection_from_payload(): void
    {
        // Arrange
        Event::fake(JobOutput::class);

        // Create a job with a specific connection
        $job = new SimpleJob;
        $job->connection = 'my-cloudtasks-connection';
        $payload = $this->createPayload($job);
        $this->setEnvVars($payload, 'test-task-name');

        // Act - no --connection needed, it extracts from payload
        $this->artisan('cloud-tasks:work-job')->assertSuccessful();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');
    }

    #[Test]
    public function it_fails_without_payload(): void
    {
        // Arrange
        putenv('CLOUD_TASKS_TASK_NAME=test-task-name');

        // Act & Assert
        $this->artisan('cloud-tasks:work-job')->assertFailed();
    }

    #[Test]
    public function it_fails_without_task_name(): void
    {
        // Arrange
        $payload = $this->createPayload(new SimpleJob);
        putenv('CLOUD_TASKS_PAYLOAD='.$payload);

        // Act & Assert
        $this->artisan('cloud-tasks:work-job')->assertFailed();
    }

    #[Test]
    public function it_fails_with_invalid_payload(): void
    {
        // Arrange
        $this->setEnvVars('not-valid-base64!!!', 'test-task-name');

        // Act & Assert
        $this->artisan('cloud-tasks:work-job')->assertFailed();
    }

    #[Test]
    public function it_handles_failing_jobs(): void
    {
        // Arrange
        Event::fake(JobOutput::class);
        $payload = $this->createPayload(new FailingJob);
        $this->setEnvVars($payload, 'test-task-name');

        // Act & Assert - The command should always return success, just like the HTTP handler.
        // Retries are managed by Laravel via $job->release(), not by Cloud Run Jobs retry mechanism.
        $this->artisan('cloud-tasks:work-job')->assertSuccessful();

        Event::assertDispatched(JobOutput::class);
    }

    #[Test]
    public function it_can_handle_encrypted_jobs(): void
    {
        // Arrange
        Event::fake(JobOutput::class);
        $payload = $this->createPayload(new EncryptedJob);
        $this->setEnvVars($payload, 'test-task-name');

        // Act
        $this->artisan('cloud-tasks:work-job')->assertSuccessful();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'EncryptedJob:success');
    }

    #[Test]
    public function uses_worker_options_callback(): void
    {
        // Arrange
        Event::fake(JobOutput::class);
        CloudTasksQueue::configureWorkerOptionsUsing(function (IncomingTask $task) {
            return new WorkerOptions(maxTries: 10);
        });

        $payload = $this->createPayload(new SimpleJob);
        $this->setEnvVars($payload, 'test-task-name');

        // Act
        $this->artisan('cloud-tasks:work-job')->assertSuccessful();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');
    }

    // ========================================
    // Cloud Run Job Dispatch Tests
    // ========================================

    #[Test]
    public function cloud_run_job_target_creates_http_request_to_run_api(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');
        $this->setConfigValue('cloud_run_job_region', 'europe-west1');

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $url = $task->getHttpRequest()->getUrl();

            return $url === 'https://run.googleapis.com/v2/projects/my-test-project/locations/europe-west1/jobs/my-worker-job:run';
        });
    }

    #[Test]
    public function cloud_run_job_target_uses_location_as_default_region(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');
        // Not setting cloud_run_job_region - should default to location

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $url = $task->getHttpRequest()->getUrl();

            // Should use 'europe-west6' from location config
            return str_contains($url, 'europe-west6');
        });
    }

    #[Test]
    public function cloud_run_job_target_posts_with_post_method(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getHttpRequest()->getHttpMethod() === HttpMethod::POST;
        });
    }

    #[Test]
    public function cloud_run_job_target_includes_container_overrides_with_env_vars(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $body = json_decode($task->getHttpRequest()->getBody(), true);

            // Check that overrides with containerOverrides.env exists
            return isset($body['overrides']['containerOverrides'][0]['env']);
        });
    }

    #[Test]
    public function cloud_run_job_target_includes_base64_encoded_payload_in_env(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $body = json_decode($task->getHttpRequest()->getBody(), true);
            $envVars = $body['overrides']['containerOverrides'][0]['env'] ?? [];

            // Find the payload env var
            foreach ($envVars as $env) {
                if ($env['name'] === 'CLOUD_TASKS_PAYLOAD') {
                    $decoded = base64_decode($env['value']);

                    return $decoded !== false && json_decode($decoded, true) !== null;
                }
            }

            return false;
        });
    }

    #[Test]
    public function cloud_run_job_target_includes_task_name_in_env(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $body = json_decode($task->getHttpRequest()->getBody(), true);
            $envVars = $body['overrides']['containerOverrides'][0]['env'] ?? [];

            // Find the task name env var
            foreach ($envVars as $env) {
                if ($env['name'] === 'CLOUD_TASKS_TASK_NAME') {
                    return ! empty($env['value']);
                }
            }

            return false;
        });
    }

    #[Test]
    public function cloud_run_job_target_sets_oauth_token_with_correct_scope(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $oauthToken = $task->getHttpRequest()->getOAuthToken();

            return $oauthToken->getScope() === 'https://www.googleapis.com/auth/cloud-platform';
        });
    }

    #[Test]
    public function cloud_run_job_target_respects_dispatch_deadline(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');
        $this->setConfigValue('dispatch_deadline', 1800);

        // Act
        $this->dispatch(new SimpleJob);

        // Assert
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            return $task->getDispatchDeadline()->getSeconds() === 1800;
        });
    }

    // ========================================
    // IncomingTask Tests for CLI Context
    // ========================================

    #[Test]
    public function incoming_task_returns_task_name_from_constructor(): void
    {
        // Arrange
        $payload = json_encode([
            'displayName' => 'SimpleJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'command' => serialize(new SimpleJob),
            ],
            'internal' => [
                'attempts' => 0,
            ],
        ]);

        // Act
        $task = IncomingTask::fromJson($payload, 'my-custom-task-name');

        // Assert
        $this->assertEquals('my-custom-task-name', $task->shortTaskName());
    }

    #[Test]
    public function incoming_task_extracts_connection_from_payload(): void
    {
        // Arrange
        $job = new SimpleJob;
        $job->connection = 'my-cloudtasks-connection';

        $payload = json_encode([
            'displayName' => 'SimpleJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'command' => serialize($job),
            ],
            'internal' => [
                'attempts' => 0,
            ],
        ]);

        // Act
        $task = IncomingTask::fromJson($payload, 'test-task');

        // Assert
        $this->assertEquals('my-cloudtasks-connection', $task->connection());
    }

    // ========================================
    // GCS Payload Storage Tests
    // ========================================

    #[Test]
    public function payload_below_threshold_is_passed_directly_in_env(): void
    {
        // Arrange
        CloudTasksApi::fake();
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');
        $this->setConfigValue('payload_disk', 'local');
        $this->setConfigValue('payload_threshold', 100000); // 100KB threshold

        // Act
        $this->dispatch(new SimpleJob);

        // Assert - should use CLOUD_TASKS_PAYLOAD directly since payload is below threshold
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $body = json_decode($task->getHttpRequest()->getBody(), true);
            $envVars = $body['overrides']['containerOverrides'][0]['env'] ?? [];

            foreach ($envVars as $env) {
                if ($env['name'] === 'CLOUD_TASKS_PAYLOAD') {
                    return true;
                }
            }

            return false;
        });
    }

    #[Test]
    public function payload_above_threshold_is_stored_in_disk(): void
    {
        // Arrange
        CloudTasksApi::fake();
        Storage::fake('local');
        $this->setConfigValue('cloud_run_job', true);
        $this->setConfigValue('cloud_run_job_name', 'my-worker-job');
        $this->setConfigValue('payload_disk', 'local');
        $this->setConfigValue('payload_prefix', 'payloads');
        $this->setConfigValue('payload_threshold', 1); // 1 byte threshold

        // Act
        $this->dispatch(new SimpleJob);

        // Assert - should use CLOUD_TASKS_PAYLOAD_PATH since payload exceeds threshold
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            $body = json_decode($task->getHttpRequest()->getBody(), true);
            $envVars = $body['overrides']['containerOverrides'][0]['env'] ?? [];

            foreach ($envVars as $env) {
                if ($env['name'] === 'CLOUD_TASKS_PAYLOAD_PATH') {
                    return true;
                }
            }

            return false;
        });

        // Assert file was created in payloads directory
        $files = Storage::disk('local')->files('payloads');
        $this->assertNotEmpty($files, 'Payload file was not created in storage');
    }

    #[Test]
    public function worker_can_process_job_from_payload_path(): void
    {
        // Arrange
        Event::fake(JobOutput::class);
        Storage::fake('local');
        $payload = $this->createPayload(new SimpleJob);
        $path = 'cloud-tasks-payloads/test-task.json';

        // Store payload in fake storage
        Storage::disk('local')->put($path, $payload);

        // Set env vars for path-based payload
        putenv('CLOUD_TASKS_TASK_NAME=test-task-name');
        putenv('CLOUD_TASKS_PAYLOAD_PATH=local:'.$path);

        // Act
        $this->artisan('cloud-tasks:work-job')->assertSuccessful();

        // Assert
        Event::assertDispatched(fn (JobOutput $event) => $event->output === 'SimpleJob:success');

        // Assert file was cleaned up
        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function worker_fails_with_invalid_payload_path_format(): void
    {
        // Arrange
        putenv('CLOUD_TASKS_TASK_NAME=test-task-name');
        putenv('CLOUD_TASKS_PAYLOAD_PATH=invalid-format-no-colon');

        // Act & Assert
        $this->artisan('cloud-tasks:work-job')->assertFailed();
    }

    #[Test]
    public function worker_fails_when_payload_file_not_found(): void
    {
        // Arrange
        Storage::fake('local');
        putenv('CLOUD_TASKS_TASK_NAME=test-task-name');
        putenv('CLOUD_TASKS_PAYLOAD_PATH=local:non-existent-file.json');

        // Act & Assert
        $this->artisan('cloud-tasks:work-job')->assertFailed();
    }
}
