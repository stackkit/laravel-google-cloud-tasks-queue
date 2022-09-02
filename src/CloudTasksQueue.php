<?php

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
use Illuminate\Support\Str;
use function Safe\json_encode;
use function Safe\json_decode;

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
     * @param string|null  $queue
     * @return int
     */
    public function size($queue = null)
    {
        // It is not possible to know the number of tasks in the queue.
        return 0;
    }

    /**
     * Fallback method for Laravel 6x and 7x
     *
     * @param  \Closure|string|object  $job
     * @param  string  $payload
     * @param  string  $queue
     * @param  \DateTimeInterface|\DateInterval|int|null  $delay
     * @param  callable  $callback
     * @return mixed
     */
    protected function enqueueUsing($job, $payload, $queue, $delay, $callback)
    {
        if (method_exists(parent::class, 'enqueueUsing')) {
            return parent::enqueueUsing($job, $payload, $queue, $delay, $callback);
        }

        return $callback($payload, $queue, $delay);
    }

    /**
     * Push a new job onto the queue.
     *
     * @param string|object  $job
     * @param mixed  $data
     * @param string|null  $queue
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
     * @param string  $payload
     * @param string|null  $queue
     * @param array  $options
     * @return string
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->pushToCloudTasks($queue, $payload);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int  $delay
     * @param string|object  $job
     * @param mixed  $data
     * @param string|null  $queue
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
     * @param string|null  $queue
     * @param string  $payload
     * @param \DateTimeInterface|\DateInterval|int $delay
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

        // Laravel 7+ jobs have a uuid, but Laravel 6 doesn't have it.
        // Since we are using and expecting the uuid in some places
        // we will add it manually here if it's not present yet.
        [$payload, $uuid] = $this->withUuid($payload);

        $httpRequest->setBody($payload);

        $task = $this->createTask();
        $task->setHttpRequest($httpRequest);

        if (!empty($this->config['dispatch_deadline'])) {
            $task->setDispatchDeadline(new Duration(['seconds' => $this->config['dispatch_deadline']]));
        }

        $token = new OidcToken;
        $token->setServiceAccountEmail($this->config['service_account_email']);
        $httpRequest->setOidcToken($token);

        if ($availableAt > time()) {
            $task->setScheduleTime(new Timestamp(['seconds' => $availableAt]));
        }

        $createdTask = CloudTasksApi::createTask($queueName, $task);

        event((new TaskCreated)->queue($queue)->task($task));

        return $uuid;
    }

    private function withUuid(string $payload): array
    {
        /** @var array $decoded */
        $decoded = json_decode($payload, true);

        if (!isset($decoded['uuid'])) {
            $decoded['uuid'] = (string) Str::uuid();
        }

        return [
            json_encode($decoded),
            $decoded['uuid'],
        ];
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

    private function createTask(): Task
    {
        return app(Task::class);
    }

    public function getHandler(): string
    {
        return Config::getHandler($this->config['handler']);
    }
}
