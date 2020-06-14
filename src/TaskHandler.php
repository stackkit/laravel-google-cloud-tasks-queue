<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

class TaskHandler
{
    /**
     * @param $task
     * @throws CloudTasksException
     */
    public function handle($task = null)
    {
        $task = $task ?: $this->captureTask();

        $this->handleTask($task);
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

        try {
            $worker->process('cloudtasks', $job, new WorkerOptions());
        } catch (Throwable $e) {
            throw new CloudTasksException('Failed handling task');
        }
    }

    /**
     * @return Worker
     */
    private function getQueueWorker()
    {
        return app('queue.worker');
    }
}
