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

    public function __construct(array $config, CloudTasksClient $client)
    {
        $this->client = $client;
        $this->config = $config;
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
     * Push a new job onto the queue.
     *
     * @param string|object  $job
     * @param mixed  $data
     * @param string|null  $queue
     * @return void
     */
    public function push($job, $data = '', $queue = null)
    {
        $this->pushToCloudTasks($queue, $this->createPayload(
            $job, $this->getQueue($queue), $data
        ));
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param string  $payload
     * @param string|null  $queue
     * @param array  $options
     * @return void
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $this->pushToCloudTasks($queue, $payload);
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
        $this->pushToCloudTasks($queue, $this->createPayload(
            $job, $this->getQueue($queue), $data
        ), $delay);
    }

    /**
     * Push a job to Cloud Tasks.
     *
     * @param string|null  $queue
     * @param string  $payload
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @return void
     */
    protected function pushToCloudTasks($queue, $payload, $delay = 0)
    {
        $queue = $this->getQueue($queue);
        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);
        $availableAt = $this->availableAt($delay);

        $httpRequest = $this->createHttpRequest();
        $httpRequest->setUrl($this->getHandler());
        $httpRequest->setHttpMethod(HttpMethod::POST);
        $httpRequest->setBody(
            // Laravel 7+ jobs have a uuid, but Laravel 6 doesn't have it.
            // Since we are using and expecting the uuid in some places
            // we will add it manually here if it's not present yet.
            $this->withUuid($payload)
        );

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
    }

    private function withUuid(string $payload): string
    {
        /** @var array $decoded */
        $decoded = json_decode($payload, true);

        if (!isset($decoded['uuid'])) {
            $decoded['uuid'] = (string) Str::uuid();
        }

        return json_encode($decoded);
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
