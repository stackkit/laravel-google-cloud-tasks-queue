<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
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

class CloudTasksQueue extends LaravelQueue implements QueueContract
{
    /**
     * @var CloudTasksClient
     */
    private $client;

    public array $config;

    public function __construct(array $config, CloudTasksClient $client, $dispatchAfterCommit = false)
    {
        $this->client = $client;
        $this->config = $config;
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function size($queue = null)
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
            $this->createPayload($job, $this->getQueue($queue), $data),
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
            $this->createPayload($job, $this->getQueue($queue), $data),
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
        $queue = $this->getQueue($queue);
        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);
        $availableAt = $this->availableAt($delay);

        $httpRequest = $this->createHttpRequest();
        $httpRequest->setUrl($this->getHandler());
        $httpRequest->setHttpMethod(HttpMethod::POST);

        $payload = json_decode($payload, true);

        // Since 3.x tasks are released back onto the queue after an exception has
        // been thrown. This means we lose the native [X-CloudTasks-TaskRetryCount] header
        // value and need to manually set and update the number of times a task has been attempted.
        $payload = $this->withAttempts($payload);

        $httpRequest->setBody(json_encode($payload));

        $task = $this->createTask();
        $task->setName($this->taskName($queue, $payload));
        $task->setHttpRequest($httpRequest);

        // The deadline for requests sent to the app. If the app does not respond by
        // this deadline then the request is cancelled and the attempt is marked as
        // a failure. Cloud Tasks will retry the task according to the RetryConfig.
        if (! empty($this->config['dispatch_deadline'])) {
            $task->setDispatchDeadline(new Duration(['seconds' => $this->config['dispatch_deadline']]));
        }

        $token = new OidcToken;
        $token->setServiceAccountEmail($this->config['service_account_email']);
        if ($audience = $this->getAudience()) {
            $token->setAudience($audience);
        }
        $httpRequest->setOidcToken($token);

        if ($availableAt > time()) {
            $task->setScheduleTime(new Timestamp(['seconds' => $availableAt]));
        }

        CloudTasksApi::createTask($queueName, $task);

        event((new TaskCreated)->queue($queue)->task($task));

        return $payload['uuid'];
    }

    private function taskName(string $queueName, array $payload): string
    {
        $displayName = $this->sanitizeTaskName($payload['displayName']);

        return CloudTasksClient::taskName(
            $this->config['project'],
            $this->config['location'],
            $queueName,
            $displayName.'-'.$payload['uuid'].'-'.Carbon::now()->getTimeStampMs(),
        );
    }

    private function sanitizeTaskName(string $taskName)
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

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        // TODO: Implement pop() method.
    }

    private function getQueue(?string $queue = null): string
    {
        return $queue ?: $this->config['queue'];
    }

    private function createHttpRequest(): HttpRequest
    {
        return app(HttpRequest::class);
    }

    public function delete(CloudTasksJob $job): void
    {
        $config = $this->config;

        $queue = $job->getQueue() ?: $this->config['queue']; // @todo: make this a helper method somewhere.

        $taskName = $this->client->taskName(
            $config['project'],
            $config['location'],
            $queue,
            (string) request()->headers->get('X-Cloudtasks-Taskname')
        );

        CloudTasksApi::deleteTask($taskName);
    }

    public function release(CloudTasksJob $job, int $delay = 0): void
    {
        $job->delete();

        $payload = $job->getRawBody();

        $options = ['delay' => $delay];

        $this->pushRaw($payload, $job->getQueue(), $options);
    }

    private function createTask(): Task
    {
        return app(Task::class);
    }

    public function getHandler(): string
    {
        return Config::getHandler($this->config['handler']);
    }

    public function getAudience(): ?string
    {
        return Config::getAudience($this->config);
    }
}
