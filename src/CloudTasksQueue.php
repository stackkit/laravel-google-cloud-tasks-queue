<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Closure;
use Exception;
use Illuminate\Support\Str;
use Google\Protobuf\Duration;

use function Safe\json_decode;
use function Safe\json_encode;

use Google\Protobuf\Timestamp;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Queue\WorkerOptions;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\OAuthToken;
use Google\Cloud\Tasks\V2\HttpRequest;
use Illuminate\Support\Facades\Storage;
use Google\Cloud\Tasks\V2\AppEngineRouting;
use Illuminate\Queue\Queue as LaravelQueue;
use Google\Cloud\Tasks\V2\AppEngineHttpRequest;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;

/**
 * @phpstan-import-type QueueConfig from CloudTasksConnector
 * @phpstan-import-type JobShape from CloudTasksJob
 * @phpstan-import-type JobBeforeDispatch from CloudTasksJob
 *
 * @phpstan-type JobOptions array{
 *     job?: Closure|string|object,
 *     delay?: ?int
 * }
 */
class CloudTasksQueue extends LaravelQueue implements QueueContract
{
    protected static ?Closure $handlerUrlCallback = null;

    protected static ?Closure $taskHeadersCallback = null;

    /** @var (Closure(IncomingTask): WorkerOptions)|null */
    protected static ?Closure $workerOptionsCallback = null;

    /**
     * @param  QueueConfig  $config
     */
    public function __construct(
        protected $config,
        public CloudTasksClient $client,
        // @phpstan-ignore-next-line
        public $dispatchAfterCommit = false,
    ) {
        //
    }

    public static function configureHandlerUrlUsing(Closure $callback): void
    {
        static::$handlerUrlCallback = $callback;
    }

    public static function forgetHandlerUrlCallback(): void
    {
        self::$handlerUrlCallback = null;
    }

    public static function setTaskHeadersUsing(Closure $callback): void
    {
        static::$taskHeadersCallback = $callback;
    }

    public static function forgetTaskHeadersCallback(): void
    {
        self::$taskHeadersCallback = null;
    }

    /**
     * @param  Closure(IncomingTask): WorkerOptions  $callback
     */
    public static function configureWorkerOptionsUsing(Closure $callback): void
    {
        static::$workerOptionsCallback = $callback;
    }

    /**
     * @return (Closure(IncomingTask): WorkerOptions)|null
     */
    public static function getWorkerOptionsCallback(): ?Closure
    {
        return self::$workerOptionsCallback;
    }

    public static function forgetWorkerOptionsCallback(): void
    {
        self::$workerOptionsCallback = null;
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     */
    public function size($queue = null): int
    {
        // It is not possible to know the number of tasks in the queue.
        return 0;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|Closure|JobBeforeDispatch  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        if (! $queue) {
            $queue = $this->getQueueForJob($job);
        }

        if (is_object($job) && ! $job instanceof Closure) {
            /** @var JobBeforeDispatch $job */
            $job->queue = $queue;
        }

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data),
            $queue,
            null,
            function ($payload, $queue) use ($job) {
                $options = ['job' => $job];

                if (is_object($job) && property_exists($job, 'delay') && $job->delay !== null) {
                    $options['delay'] = $job->delay;
                }

                return $this->pushRaw($payload, $queue, $options);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  JobOptions  $options
     * @return string
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $delay = ! empty($options['delay']) ? $options['delay'] : 0;
        $job = $options['job'] ?? null;

        return $this->pushToCloudTasks($queue, $payload, $delay, $job);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  Closure|string|JobBeforeDispatch  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        // Laravel pls fix your typehints
        if (! $queue) {
            $queue = $this->getQueueForJob($job);
        }

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) use ($job) {
                return $this->pushToCloudTasks($queue, $payload, $delay, $job);
            }
        );
    }

    /**
     * Push a job to Cloud Tasks.
     *
     * @param  string|null  $queue
     * @param  string  $payload
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  Closure|string|object|null  $job
     * @return string
     */
    protected function pushToCloudTasks($queue, $payload, $delay, mixed $job)
    {
        $queue = $queue ?: $this->config['queue'];

        $payload = (array) json_decode($payload, true);

        /** @var JobShape $payload */
        $task = tap(new Task)->setName($this->taskName($queue, $payload['displayName']));

        $payload = $this->enrichPayloadWithAttempts($payload);

        $this->addPayloadToTask($payload, $task, $job);

        $availableAt = $this->availableAt($delay);
        if ($availableAt > time()) {
            $task->setScheduleTime(new Timestamp(['seconds' => $availableAt]));
        }

        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);
        CloudTasksApi::createTask($queueName, $task);

        event(new TaskCreated($queue, $task));

        return $payload['uuid'];
    }

    private function taskName(string $queueName, string $displayName): string
    {
        return CloudTasksClient::taskName(
            $this->config['project'],
            $this->config['location'],
            $queueName,
            str($displayName)
                ->afterLast('\\')
                ->replaceMatches('![^-\pL\pN\s]+!u', '-')
                ->replaceMatches('![-\s]+!u', '-')
                ->prepend((string) Str::ulid(), '-')
                ->toString(),
        );
    }

    /**
     * @param  JobShape  $payload
     * @return JobShape
     */
    private function enrichPayloadWithAttempts(array $payload): array
    {
        $payload['internal'] = [
            'attempts' => $payload['internal']['attempts'] ?? 0,
        ];

        return $payload;
    }

    /**
     * @param  Closure|string|object|null  $job
     * @param  JobShape  $payload
     */
    public function addPayloadToTask(array $payload, Task $task, $job): Task
    {
        $headers = $this->headers($payload);

        if (! empty($this->config['app_engine'])) {
            $path = \Safe\parse_url(route('cloud-tasks.handle-task'), PHP_URL_PATH);

            if (! is_string($path)) {
                throw new Exception('Something went wrong parsing the route.');
            }

            $appEngineRequest = new AppEngineHttpRequest;
            $appEngineRequest->setRelativeUri($path);
            $appEngineRequest->setHttpMethod(HttpMethod::POST);
            $appEngineRequest->setBody(json_encode($payload));
            $appEngineRequest->setHeaders($headers);

            if (! empty($this->config['app_engine_service'])) {
                $routing = new AppEngineRouting;
                $routing->setService($this->config['app_engine_service']);
                $appEngineRequest->setAppEngineRouting($routing);
            }

            $task->setAppEngineHttpRequest($appEngineRequest);
        } elseif (! empty($this->config['cloud_run_job'])) {
            // Cloud Run Job target - call the Cloud Run Jobs execution API
            $httpRequest = new HttpRequest;
            $httpRequest->setUrl($this->getCloudRunJobExecutionUrl());
            $httpRequest->setHttpMethod(HttpMethod::POST);
            $httpRequest->setHeaders(array_merge($headers, [
                'Content-Type' => 'application/json',
            ]));

            // Build the execution request body with container overrides
            // The job payload is passed as environment variables
            $taskNameShort = str($task->getName())->afterLast('/')->toString();
            $encodedPayload = base64_encode(json_encode($payload));

            // Build env vars for the container using fixed env var names
            // These map to config keys: cloud_run_job_payload, cloud_run_job_task_name, cloud_run_job_payload_path
            $envVars = $this->getCloudRunJobEnvVars($encodedPayload, $taskNameShort);

            $executionBody = [
                'overrides' => [
                    'containerOverrides' => [
                        [
                            'env' => $envVars,
                        ],
                    ],
                ],
            ];

            $httpRequest->setBody(json_encode($executionBody));

            $token = new OAuthToken;
            $token->setServiceAccountEmail($this->config['service_account_email'] ?? '');
            $token->setScope('https://www.googleapis.com/auth/cloud-platform');
            $httpRequest->setOAuthToken($token);
            $task->setHttpRequest($httpRequest);

            if (! empty($this->config['dispatch_deadline'])) {
                $task->setDispatchDeadline((new Duration)->setSeconds($this->config['dispatch_deadline']));
            }
        } else {
            $httpRequest = new HttpRequest;
            $httpRequest->setUrl($this->getHandler($job));
            $httpRequest->setBody(json_encode($payload));
            $httpRequest->setHttpMethod(HttpMethod::POST);
            $httpRequest->setHeaders($headers);

            $token = new OidcToken;
            $token->setServiceAccountEmail($this->config['service_account_email'] ?? '');
            $httpRequest->setOidcToken($token);
            $task->setHttpRequest($httpRequest);

            if (! empty($this->config['dispatch_deadline'])) {
                $task->setDispatchDeadline((new Duration)->setSeconds($this->config['dispatch_deadline']));
            }
        }

        return $task;
    }

    public function pop($queue = null)
    {
        // It is not possible to pop a job from the queue.
        return null;
    }

    public function delete(CloudTasksJob $job): void
    {
        // Job deletion will be handled by Cloud Tasks.
    }

    public function release(CloudTasksJob $job, int $delay = 0): void
    {
        $this->pushRaw(
            payload: $job->getRawBody(),
            queue: $job->getQueue(),
            options: ['delay' => $delay, 'job' => $job],
        );
    }

    /**
     * @param  Closure|string|object|null  $job
     */
    public function getHandler(mixed $job): string
    {
        if (static::$handlerUrlCallback) {
            return (static::$handlerUrlCallback)($job);
        }

        if (empty($this->config['handler'])) {
            $this->config['handler'] = request()->getSchemeAndHttpHost();
        }

        $handler = rtrim($this->config['handler'], '/');

        if (str_ends_with($handler, '/'.config()->string('cloud-tasks.uri'))) {
            return $handler;
        }

        return $handler.'/'.config()->string('cloud-tasks.uri');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function headers(mixed $payload): array
    {
        if (! static::$taskHeadersCallback) {
            return [];
        }

        return (static::$taskHeadersCallback)($payload);
    }

    /**
     * @param  Closure|string|JobBeforeDispatch  $job
     */
    private function getQueueForJob(mixed $job): string
    {
        if (is_object($job) && ! $job instanceof Closure) {
            /** @var JobBeforeDispatch $job */
            if (! empty($job->queue)) {
                return $job->queue;
            }
        }

        return $this->config['queue'];
    }

    /**
     * Get the Cloud Run Jobs execution API URL.
     */
    private function getCloudRunJobExecutionUrl(): string
    {
        $project = $this->config['project'];
        $region = $this->config['cloud_run_job_region'] ?? $this->config['location'];
        $jobName = $this->config['cloud_run_job_name'] ?? throw new Exception('cloud_run_job_name is required when using Cloud Run Jobs.');

        return sprintf(
            'https://run.googleapis.com/v2/projects/%s/locations/%s/jobs/%s:run',
            $project,
            $region,
            $jobName
        );
    }

    /**
     * Get the environment variables for Cloud Run Job dispatch.
     *
     * If the payload exceeds the configured threshold, it will be stored
     * in the configured disk and the path will be returned instead.
     *
     * Env vars set map to config keys in the queue connection:
     * - CLOUD_TASKS_TASK_NAME -> cloud_run_job_task_name
     * - CLOUD_TASKS_PAYLOAD -> cloud_run_job_payload
     * - CLOUD_TASKS_PAYLOAD_PATH -> cloud_run_job_payload_path
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function getCloudRunJobEnvVars(string $encodedPayload, string $taskName): array
    {
        $disk = $this->config['payload_disk'] ?? null;
        $threshold = $this->config['payload_threshold'] ?? 10240; // 10KB default

        $envVars = [
            ['name' => 'CLOUD_TASKS_TASK_NAME', 'value' => $taskName],
        ];

        // If no disk configured or payload is below threshold, pass payload directly
        if ($disk === null || strlen($encodedPayload) <= $threshold) {
            $envVars[] = ['name' => 'CLOUD_TASKS_PAYLOAD', 'value' => $encodedPayload];

            return $envVars;
        }

        // Store payload in configured disk and pass path instead
        $prefix = $this->config['payload_prefix'] ?? 'cloud-tasks-payloads';
        $timestamp = now()->format('Y-m-d_H:i:s.v');
        $path = sprintf('%s/%s_%s.json', $prefix, $timestamp, $taskName);

        Storage::disk($disk)->put($path, $encodedPayload);

        // Set the path env var for large payloads
        $envVars[] = ['name' => 'CLOUD_TASKS_PAYLOAD_PATH', 'value' => $disk.':'.$path];

        return $envVars;
    }

    public function pause(string $queue): void
    {
        $queueName = CloudTasksClient::queueName($this->config['project'], $this->config['location'], $queue);

        CloudTasksApi::pause($queue);
    }

    public function resume(string $queue): void
    {
        $queueName = CloudTasksClient::queueName($this->config['project'], $this->config['location'], $queue);

        CloudTasksApi::resume($queue);
    }
}
