<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue\Commands;

use Exception;
use Illuminate\Console\Command;

use function Safe\base64_decode;

use Illuminate\Container\Container;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Storage;
use Stackkit\LaravelGoogleCloudTasksQueue\Worker;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

/**
 * Artisan command to process Cloud Tasks jobs via Cloud Run Jobs.
 *
 * This command allows jobs to be processed without an HTTP endpoint,
 * making it suitable for Cloud Run Jobs which run as container executions.
 *
 * The job payload and task name are read from environment variables set by Cloud Tasks:
 *   - CLOUD_TASKS_TASK_NAME (required)
 *   - CLOUD_TASKS_PAYLOAD or CLOUD_TASKS_PAYLOAD_PATH (one required)
 *
 * The connection is extracted from the payload itself.
 *
 * @phpstan-import-type QueueConfig from \Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksConnector
 */
class WorkCloudRunJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloud-tasks:work-job';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process a Cloud Tasks job via Cloud Run Job';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Read task name from environment variable
        $taskName = $this->getEnvValue('CLOUD_TASKS_TASK_NAME');
        if ($taskName === null) {
            $this->error('Required environment variable CLOUD_TASKS_TASK_NAME is not set.');

            return self::FAILURE;
        }

        // Get payload from environment - either direct or from path
        $payload = $this->getPayload();
        if ($payload === null) {
            $this->error('Required environment variable CLOUD_TASKS_PAYLOAD or CLOUD_TASKS_PAYLOAD_PATH is not set.');

            return self::FAILURE;
        }

        try {
            $decodedPayload = base64_decode($payload);
            $task = IncomingTask::fromJson($decodedPayload, $taskName);
        } catch (Exception $e) {
            $this->error('Failed to decode payload: '.$e->getMessage());

            return self::FAILURE;
        }

        // Get connection from the payload
        $connectionName = $task->connection();

        /** @var QueueConfig $config */
        $config = config('queue.connections.'.$connectionName);

        $client = app(CloudTasksClient::class);
        $queue = tap(new CloudTasksQueue($config, $client))->setConnectionName($connectionName);

        $job = new CloudTasksJob(
            container: Container::getInstance(),
            driver: $queue,
            job: $task->toArray(),
            connectionName: $connectionName,
            queue: $task->queue(),
        );

        $job->setAttempts($job->attempts() + 1);

        /** @var Worker $worker */
        $worker = app('cloud-tasks.worker');

        // We manually manage retries by releasing jobs (which pushes a new task back to Cloud Tasks),
        // so we never want to return a failure exit code as that will result in duplicate job attempts
        // if retries are configured on the cloud run job.
        rescue(fn () => $worker->process(
            connectionName: $job->getConnectionName(),
            job: $job,
            options: CloudTasksQueue::getWorkerOptionsCallback()
                ? (CloudTasksQueue::getWorkerOptionsCallback())($task)
                : $this->getWorkerOptions($config)
        ));

        $this->info('Job processed.');

        return self::SUCCESS;
    }

    /**
     * Get the payload from environment variable or storage.
     */
    private function getPayload(): ?string
    {
        // First check for direct payload
        $payload = $this->getEnvValue('CLOUD_TASKS_PAYLOAD');
        if ($payload !== null) {
            return $payload;
        }

        // Check for payload path (for large payloads stored in filesystem)
        $payloadPath = $this->getEnvValue('CLOUD_TASKS_PAYLOAD_PATH');
        if ($payloadPath !== null) {
            return $this->fetchPayloadFromStorage($payloadPath);
        }

        return null;
    }

    /**
     * Get an environment variable value.
     */
    private function getEnvValue(string $name): ?string
    {
        $value = getenv($name);

        return $value !== false && $value !== '' ? $value : null;
    }

    /**
     * Fetch payload from Laravel filesystem storage and clean up.
     */
    private function fetchPayloadFromStorage(string $payloadPath): ?string
    {
        // Parse format: disk:path
        if (! str_contains($payloadPath, ':')) {
            $this->error('Invalid payload path format. Expected: disk:path');

            return null;
        }

        [$disk, $path] = explode(':', $payloadPath, 2);

        if (! Storage::disk($disk)->exists($path)) {
            $this->error("Payload file not found: {$payloadPath}");

            return null;
        }

        $payload = Storage::disk($disk)->get($path);

        // Clean up the file after reading
        Storage::disk($disk)->delete($path);

        return $payload;
    }

    /**
     * Get the worker options for the job.
     *
     * @param  QueueConfig  $config
     */
    private function getWorkerOptions(array $config): WorkerOptions
    {
        $options = new WorkerOptions;

        if (isset($config['backoff'])) {
            $options->backoff = $config['backoff'];
        }

        return $options;
    }
}
