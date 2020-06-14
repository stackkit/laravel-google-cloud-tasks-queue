<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Ahc\Jwt\JWT;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Http\Request;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

class TaskHandler
{
    private $client;
    private $request;

    public function __construct(CloudTasksClient $client, Request $request)
    {
        $this->client = $client;
        $this->request = $request;
    }

    /**
     * @param $task
     * @throws CloudTasksException
     */
    public function handle($task = null)
    {
        $this->authorizeRequest();

        $task = $task ?: $this->captureTask();

        $this->handleTask($task);
    }

    /**
     * @throws CloudTasksException
     */
    private function authorizeRequest()
    {
        $this->checkForRequiredHeaders();

        $taskName = $this->request->header('X-Cloudtasks-Taskname');
        $queueName = $this->request->header('X-Cloudtasks-Queuename');
        $authToken = $this->request->header('X-Stackkit-Auth-Token');

        $fullQueueName = $this->client->queueName(Config::project(), Config::location(), $queueName);

        try {
            $this->client->getTask($fullQueueName . '/tasks/' . $taskName);
        } catch (Throwable $e) {
            throw new CloudTasksException('Could not find task');
        }

        if (decrypt($authToken) != $taskName) {
            throw new CloudTasksException('Auth token is not valid');
        }
    }

    private function checkForRequiredHeaders()
    {
        $headers = [
            'X-Cloudtasks-Taskname',
            'X-Cloudtasks-Queuename',
            'X-Stackkit-Auth-Token',
        ];

        foreach ($headers as $header) {
            if (!$this->request->hasHeader($header)) {
                throw new CloudTasksException('Missing [' . $header . '] header');
            }
        }
    }

    /**
     * @throws CloudTasksException
     */
    private function captureTask()
    {
        $input = file_get_contents('php://input');

        if ($input === false) {
            throw new CloudTasksException('Could not read incoming task');
        }

        $task = json_decode($input, true, JSON_THROW_ON_ERROR);

        if (is_null($task)) {
            throw new CloudTasksException('Could not decode incoming task');
        }

        return $task;
    }

    /**
     * @param $task
     * @throws CloudTasksException
     */
    private function handleTask($task)
    {
        $job = new CloudTasksJob($task, request()->header('X-CloudTasks-TaskRetryCount'));

        $worker = $this->getQueueWorker();

        $worker->process('cloudtasks', $job, new WorkerOptions());
    }

    /**
     * @return Worker
     */
    private function getQueueWorker()
    {
        return app('queue.worker');
    }
}
