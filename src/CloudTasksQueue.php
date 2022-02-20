<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as LaravelQueue;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;

class CloudTasksQueue extends LaravelQueue implements QueueContract
{
    use InteractsWithTime;

    private $client;
    private $default;
    public $config;

    public function __construct(array $config, CloudTasksClient $client)
    {
        $this->client = $client;
        $this->default = $config['queue'];
        $this->config = $config;
    }

    public function size($queue = null)
    {
        // TODO: Implement size() method.
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushToCloudTasks($queue, $this->createPayload(
            $job, $this->getQueue($queue), $data
        ));
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->pushToCloudTasks($queue, $payload);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushToCloudTasks($queue, $this->createPayload(
            $job, $this->getQueue($queue), $data
        ), $delay);
    }

    protected function pushToCloudTasks($queue, $payload, $delay = 0, $attempts = 0)
    {
        $queue = $this->getQueue($queue);
        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);
        $availableAt = $this->availableAt($delay);

        $httpRequest = $this->createHttpRequest();
        $httpRequest->setUrl($this->config['handler']);
        $httpRequest->setHttpMethod(HttpMethod::POST);
        $httpRequest->setBody(
            // Laravel 7+ jobs have a uuid, but Laravel 6 doesn't have it.
            // Since we are using and expecting the uuid in some places
            // we will add it manually here if it's not present yet.
            $this->withUuid($payload)
        );

        $task = $this->createTask();
        $task->setHttpRequest($httpRequest);

        $token = new OidcToken;
        $token->setServiceAccountEmail($this->config['service_account_email']);
        $httpRequest->setOidcToken($token);

        if ($availableAt > time()) {
            $task->setScheduleTime(new Timestamp(['seconds' => $availableAt]));
        }

        MonitoringService::make()->addToMonitor($queue, $task);

        $createdTask = CloudTasksApi::createTask($queueName, $task);

        event(new TaskCreated($createdTask));
    }

    private function withUuid(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (!isset($decoded['uuid'])) {
            $decoded['uuid'] = (string) Str::uuid();
        }

        return json_encode($decoded);
    }

    public function pop($queue = null)
    {
        // TODO: Implement pop() method.
    }

    private function getQueue($queue = null)
    {
        return $queue ?: $this->default;
    }

    /**
     * @return HttpRequest
     */
    private function createHttpRequest()
    {
        return app(HttpRequest::class);
    }

    public function delete(CloudTasksJob $job)
    {
        $config = $this->config;

        $queue = $job->getQueue() ?: $this->config['queue']; // @todo: make this a helper method somewhere.

        $taskName = $this->client->taskName(
            $config['project'],
            $config['location'],
            $queue,
            request()->header('X-Cloudtasks-Taskname')
        );

        CloudTasksApi::deleteTask($taskName);
    }

    /**
     * @return Task
     */
    private function createTask()
    {
        return app(Task::class);
    }
}
