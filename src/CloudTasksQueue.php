<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Carbon\Carbon;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as LaravelQueue;
use Illuminate\Support\InteractsWithTime;

class CloudTasksQueue extends LaravelQueue implements QueueContract
{
    use InteractsWithTime;

    private $client;
    private $default;

    public function __construct(array $config, CloudTasksClient $client)
    {
        $this->client = $client;
        $this->default = $config['queue'];
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
        $queueName = $this->client->queueName(Config::project(), Config::location(), $queue);
        $availableAt = $this->availableAt($delay);

        $httpRequest = app(HttpRequest::class);
        $httpRequest->setUrl(Config::handler());
        $httpRequest->setHttpMethod(HttpMethod::POST);
        $httpRequest->setBody($payload);

        $task = app(Task::class);
        $task->setHttpRequest($httpRequest);

        if ($availableAt > time()) {
            $task->setScheduleTime(new Timestamp(['seconds' => $availableAt]));
        }

        $this->client->createTask($queueName, $task);
    }

    public function pop($queue = null)
    {
        // TODO: Implement pop() method.
    }

    private function getQueue($queue = null)
    {
        return $queue ?: $this->default;
    }
}
