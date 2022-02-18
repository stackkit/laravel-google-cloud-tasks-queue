<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Task;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonitoringService
{
    public static function make()
    {
        return new MonitoringService();
    }

    public function addToMonitor($queue, Task $task)
    {
        $metadata = new TaskMetadata();
        $metadata->payload = $task->getHttpRequest()->getBody();
        $metadata->addEvent('queued', [
            'queue' => $queue,
        ]);

        DB::table('stackkit_cloud_tasks')
            ->insert([
                'task_uuid' => $this->getTaskUuid($task),
                'name' => $this->getTaskName($task),
                'queue' => $queue,
                'payload' =>  $task->getHttpRequest()->getBody(),
                'status' => 'queued',
                'metadata' => $metadata->toJson(),
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
    }

    public function markAsRunning($uuid)
    {
        $task = StackkitCloudTask::whereTaskUuid($uuid)->firstOrFail();

        $task->status = 'running';
        $metadata = $task->getMetadata();
        $events = Arr::get($metadata, 'events', []);
        $events[] = [
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ];
        $task->setMetadata('events', $events);

        $task->save();
    }

    public function markAsSuccessful($uuid)
    {
        $task = StackkitCloudTask::whereTaskUuid($uuid)->firstOrFail();

        $task->status = 'successful';
        $metadata = $task->getMetadata();
        $events = Arr::get($metadata, 'events', []);
        $events[] = [
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ];
        $task->setMetadata('events', $events);

        $task->save();
    }

    public function markAsError(JobExceptionOccurred $event)
    {
        $task = StackkitCloudTask::whereTaskUuid($event->job->uuid())
            ->first();

        if (!$task) {
            return;
        }

        if ($task->status === 'failed') {
            return;
        }

        $task->status = 'error';
        $metadata = $task->getMetadata();
        $events = Arr::get($metadata, 'events', []);
        $events[] = [
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ];
        $task->setMetadata('events', $events);
        $task->setMetadata('exception', (string) $event->exception);

        $task->save();
    }

    public function markAsFailed(JobFailed $event)
    {
        $task = StackkitCloudTask::whereTaskUuid($event->job->uuid())->firstOrFail();

        $task->status = 'failed';
        $metadata = $task->getMetadata();
        $events = Arr::get($metadata, 'events', []);
        $events[] = [
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ];
        $task->setMetadata('events', $events);

        $task->save();
    }

    private function getTaskName(Task $task)
    {
        $decode = json_decode($task->getHttpRequest()->getBody(), true);

        return $decode['displayName'];
    }

    private function getTaskUuid(Task $task)
    {
        return json_decode($task->getHttpRequest()->getBody())->uuid;
    }
}
