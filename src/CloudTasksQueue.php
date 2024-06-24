<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Closure;
use Google\Cloud\Tasks\V2\AppEngineHttpRequest;
use Google\Cloud\Tasks\V2\AppEngineRouting;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as LaravelQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Str;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;

use function Safe\json_decode;
use function Safe\json_encode;

class CloudTasksQueue extends LaravelQueue implements QueueContract
{
    private static ?Closure $handlerUrlCallback = null;

    private static ?Closure $taskHeadersCallback = null;

    /** @var (Closure(IncomingTask): WorkerOptions)|null */
    private static ?Closure $workerOptionsCallback = null;

    public function __construct(public array $config, public CloudTasksClient $client, public $dispatchAfterCommit = false)
    {
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
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return void
     */
    public function push($job, $data = '', $queue = null)
    {
        if (! ($job instanceof Closure)) {
            $job->queue = $queue ?? $job->queue ?? $this->config['queue'];
        }

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data),
            $queue,
            null,
            function ($payload, $queue) use ($job) {
                return $this->pushRaw($payload, $queue, ['job' => $job]);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string|null  $queue
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
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return void
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
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
     * @param  string|object  $job
     * @return string
     */
    protected function pushToCloudTasks($queue, $payload, $delay, mixed $job)
    {
        $queue = $queue ?: $this->config['queue'];

        $payload = (array) json_decode($payload, true);

        $task = tap(new Task())->setName($this->taskName($queue, $payload['displayName']));

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

    private function enrichPayloadWithAttempts(
        array $payload,
    ): array {
        $payload['internal'] = [
            'attempts' => $payload['internal']['attempts'] ?? 0,
        ];

        return $payload;
    }

    /** @param string|object $job */
    public function addPayloadToTask(array $payload, Task $task, mixed $job): Task
    {
        $headers = $this->headers($payload);

        if (! empty($this->config['app_engine'])) {
            $path = \Safe\parse_url(route('cloud-tasks.handle-task'), PHP_URL_PATH);

            $appEngineRequest = new AppEngineHttpRequest();
            $appEngineRequest->setRelativeUri($path);
            $appEngineRequest->setHttpMethod(HttpMethod::POST);
            $appEngineRequest->setBody(json_encode($payload));
            $appEngineRequest->setHeaders($headers);

            if (! empty($service = $this->config['app_engine_service'])) {
                $routing = new AppEngineRouting();
                $routing->setService($service);
                $appEngineRequest->setAppEngineRouting($routing);
            }

            $task->setAppEngineHttpRequest($appEngineRequest);
        } else {
            $httpRequest = new HttpRequest();
            $httpRequest->setUrl($this->getHandler($job));
            $httpRequest->setBody(json_encode($payload));
            $httpRequest->setHttpMethod(HttpMethod::POST);
            $httpRequest->setHeaders($headers);

            $token = new OidcToken;
            $token->setServiceAccountEmail($this->config['service_account_email']);
            $httpRequest->setOidcToken($token);
            $task->setHttpRequest($httpRequest);
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

    /** @param string|object $job */
    public function getHandler(mixed $job): string
    {
        if (static::$handlerUrlCallback) {
            return (static::$handlerUrlCallback)($job);
        }

        if (empty($this->config['handler'])) {
            $this->config['handler'] = request()->getSchemeAndHttpHost();
        }

        $handler = rtrim($this->config['handler'], '/');

        if (str_ends_with($handler, '/'.config('cloud-tasks.uri'))) {
            return $handler;
        }

        return $handler.'/'.config('cloud-tasks.uri');
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
}
