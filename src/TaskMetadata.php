<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

class TaskMetadata
{
    /**
     * @var array
     */
    public $events = [];

    /**
     * @var string
     */
    public $payload;

    /**
     * @param string $status
     * @return void
     */
    public function addEvent($status, array $additional = [])
    {
        $event = [
            'status' => $status,
            'datetime' => now()->utc()->toDateTimeString(),
        ];

        $this->events[] = array_merge($additional, $event);
    }

    public function toArray()
    {
        return [
            'events' => $this->events,
            'payload' => $this->payload,
        ];
    }

    public function toJson()
    {
        return json_encode($this->toArray());
    }

    public static function createFromArray(array $data)
    {
        $metadata = new TaskMetadata();

        $metadata->events = $data['events'];
        $metadata->payload = $data['payload'];

        return $metadata;
    }
}