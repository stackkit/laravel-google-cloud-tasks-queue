<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\AppEngineHttpRequest;
use Google\Cloud\Tasks\V2\AppEngineRouting;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Duration;
use Google\Protobuf\Timestamp;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as LaravelQueue;
use Illuminate\Support\Carbon;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;

use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\preg_replace;

class CloudTasksQueue extends LaravelQueue implements QueueContract
{
    public function __construct(public array $config, public CloudTasksClient $client, public $dispatchAfterCommit = false)
    {
        //
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
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data),
            $queue,
            null,
            function ($payload, $queue) {
                return $this->pushRaw($payload, $queue);
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

        return $this->pushToCloudTasks($queue, $payload, $delay);
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
            function ($payload, $queue, $delay) {
                return $this->pushToCloudTasks($queue, $payload, $delay);
            }
        );
    }

    /**
     * Push a job to Cloud Tasks.
     *
     * @param  string|null  $queue
     * @param  string  $payload
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @return string
     */
    protected function pushToCloudTasks($queue, $payload, $delay = 0)
    {
        $queue = $queue ?: $this->config['queue'];

        $payload = (array) json_decode($payload, true);

        $task = new Task([
            'name' => $this->taskName($queue, $payload),
        ]);

        $payload = $this->withAttempts($payload);
        $payload = $this->withQueueName($payload, $queue);
        $payload = $this->withTaskName($payload, $task->getName());
        $payload = $this->withConnectionName($payload, $this->getConnectionName());
        $payload = $this->withSecurityKey($payload);

        if (! empty($this->config['app_engine'])) {
            $path = \Safe\parse_url(route('cloud-tasks.handle-task'), PHP_URL_PATH);

            $appEngineRequest = new AppEngineHttpRequest();
            $appEngineRequest->setRelativeUri($path);
            $appEngineRequest->setHttpMethod(HttpMethod::POST);
            $appEngineRequest->setBody(json_encode($payload));
            if (! empty($service = $this->config['app_engine_service'])) {
                $routing = new AppEngineRouting();
                $routing->setService($service);
                $appEngineRequest->setAppEngineRouting($routing);
            }
            $task->setAppEngineHttpRequest($appEngineRequest);
        } else {
            $httpRequest = new HttpRequest();
            $httpRequest->setUrl($this->getHandler());
            $httpRequest->setHttpMethod(HttpMethod::POST);

            $httpRequest->setBody(json_encode($payload));

            $token = new OidcToken;
            $token->setServiceAccountEmail($this->config['service_account_email']);
            $httpRequest->setOidcToken($token);
            $task->setHttpRequest($httpRequest);
        }

        // The deadline for requests sent to the app. If the app does not respond by
        // this deadline then the request is cancelled and the attempt is marked as
        // a failure. Cloud Tasks will retry the task according to the RetryConfig.
        if (! empty($this->config['dispatch_deadline'])) {
            $task->setDispatchDeadline(new Duration(['seconds' => $this->config['dispatch_deadline']]));
        }

        $availableAt = $this->availableAt($delay);
        if ($availableAt > time()) {
            $task->setScheduleTime(new Timestamp(['seconds' => $availableAt]));
        }

        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);
        CloudTasksApi::createTask($queueName, $task);

        event(new TaskCreated($queue, $task));

        return $payload['uuid'];
    }

    private function taskName(string $queueName, array $payload): string
    {
        $displayName = $this->sanitizeTaskName($payload['displayName']);

        return CloudTasksClient::taskName(
            $this->config['project'],
            $this->config['location'],
            $queueName,
            $displayName.'-'. bin2hex(random_bytes(8)),
        );
    }

    private function sanitizeTaskName(string $taskName): string
    {
        // Remove all characters that are not -, letters, numbers, or whitespace
        $sanitizedName = preg_replace('![^-\pL\pN\s]+!u', '-', $taskName);

        // Replace all separator characters and whitespace by a -
        $sanitizedName = preg_replace('![-\s]+!u', '-', $sanitizedName);

        return trim($sanitizedName, '-');
    }

    private function withAttempts(array $payload): array
    {
        if (! isset($payload['internal']['attempts'])) {
            $payload['internal']['attempts'] = 0;
        }

        return $payload;
    }

    private function withQueueName(array $payload, string $queueName): array
    {
        $payload['internal']['queue'] = $queueName;

        return $payload;
    }

    private function withTaskName(array $payload, string $taskName): array
    {
        $payload['internal']['taskName'] = $taskName;

        return $payload;
    }

    private function withConnectionName(array $payload, string $connectionName): array
    {
        $payload['internal']['connection'] = $connectionName;

        return $payload;
    }

    private function withSecurityKey(array $payload): array
    {
        $payload['internal']['securityKey'] = encrypt($this->config['security_key'] ?? $payload['uuid']);

        return $payload;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        //
    }

    public function delete(CloudTasksJob $job): void
    {
        CloudTasksApi::deleteTask($job->getTaskName());
    }

    public function release(CloudTasksJob $job, int $delay = 0): void
    {
        $job->delete();

        $payload = $job->getRawBody();

        $options = ['delay' => $delay];

        $this->pushRaw($payload, $job->getQueue(), $options);
    }

    public function getHandler(): string
    {
        if (empty($this->config['handler'])) {
            $this->config['handler'] = request()->getSchemeAndHttpHost();
        }

        $handler = rtrim($this->config['handler'], '/');

        if (str_ends_with($handler, '/handle-task')) {
            return $handler;
        }

        return $handler.'/handle-task';
    }
}
