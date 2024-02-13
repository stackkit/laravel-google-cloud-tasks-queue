<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\AppEngineHttpRequest;
use Google\Cloud\Tasks\V2\AppEngineRouting;
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
use Illuminate\Support\Str;
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
     * @param string|null $queue
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
     * @param \Closure|string|object $job
     * @param string $payload
     * @param string $queue
     * @param \DateTimeInterface|\DateInterval|int|null $delay
     * @param callable $callback
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
     * @param string|object $job
     * @param mixed $data
     * @param string|null $queue
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
     * @param string $payload
     * @param string|null $queue
     * @param array $options
     * @return string
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $delay = !empty($options['delay']) ? $options['delay'] : 0;

        return $this->pushToCloudTasks($queue, $payload, $delay);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param string|object $job
     * @param mixed $data
     * @param string|null $queue
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
     * @param string|null $queue
     * @param string $payload
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @return string
     */
    protected function pushToCloudTasks($queue, $payload, $delay = 0)
    {
        $queue = $this->getQueue($queue);
        $queueName = $this->client->queueName($this->config['project'], $this->config['location'], $queue);
        $availableAt = $this->availableAt($delay);

        $payload = json_decode($payload, true);

        // Laravel 7+ jobs have a uuid, but Laravel 6 doesn't have it.
        // Since we are using and expecting the uuid in some places
        // we will add it manually here if it's not present yet.
        $payload = $this->withUuid($payload);

        // Since 3.x tasks are released back onto the queue after an exception has
        // been thrown. This means we lose the native [X-CloudTasks-TaskRetryCount] header
        // value and need to manually set and update the number of times a task has been attempted.
        $payload = $this->withAttempts($payload);

        $task = $this->createTask();
        $task->setName($this->taskName($queue, $payload));

        if (!empty($this->config['app_engine'])) {
            $path = \Safe\parse_url(route('cloud-tasks.handle-task'), PHP_URL_PATH);

            $appEngineRequest = new AppEngineHttpRequest();
            $appEngineRequest->setRelativeUri($path);
            $appEngineRequest->setHttpMethod(HttpMethod::POST);
            $appEngineRequest->setBody(json_encode($payload));
            if (!empty($service = $this->config['app_engine_service'])) {
                $routing = new AppEngineRouting();
                $routing->setService($service);
                $appEngineRequest->setAppEngineRouting($routing);
            }
            $task->setAppEngineHttpRequest($appEngineRequest);
        } else {
            $httpRequest = $this->createHttpRequest();
            $httpRequest->setUrl($this->getHandler());
            $httpRequest->setHttpMethod(HttpMethod::POST);

            $httpRequest->setBody(json_encode($payload));

            $token = new OidcToken;
            $token->setServiceAccountEmail($this->config['service_account_email']);
            if ($audience = $this->getAudience()) {
                $token->setAudience($audience);
            }
            $httpRequest->setOidcToken($token);
            $task->setHttpRequest($httpRequest);
        }


        // The deadline for requests sent to the app. If the app does not respond by
        // this deadline then the request is cancelled and the attempt is marked as
        // a failure. Cloud Tasks will retry the task according to the RetryConfig.
        if (!empty($this->config['dispatch_deadline'])) {
            $task->setDispatchDeadline(new Duration(['seconds' => $this->config['dispatch_deadline']]));
        }

        if ($availableAt > time()) {
            $task->setScheduleTime(new Timestamp(['seconds' => $availableAt]));
        }

        CloudTasksApi::createTask($queueName, $task);

        event((new TaskCreated)->queue($queue)->task($task));

        return $payload['uuid'];
    }

    private function withUuid(array $payload): array
    {
        if (!isset($payload['uuid'])) {
            $payload['uuid'] = (string)Str::uuid();
        }

        return $payload;
    }

    private function taskName(string $queueName, array $payload): string
    {
        $displayName = $this->sanitizeTaskName($payload['displayName']);

        return CloudTasksClient::taskName(
            $this->config['project'],
            $this->config['location'],
            $queueName,
            $displayName . '-' . $payload['uuid'] . '-' . Carbon::now()->getTimeStampMs(),
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
        if (!isset($payload['internal']['attempts'])) {
            $payload['internal']['attempts'] = 0;
        }

        return $payload;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
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

        $headerTaskName = request()->headers->get('X-Cloudtasks-Taskname')
            ?? request()->headers->get('X-AppEngine-TaskName');
        $taskName = $this->client->taskName(
            $config['project'],
            $config['location'],
            $queue,
            (string)$headerTaskName
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
