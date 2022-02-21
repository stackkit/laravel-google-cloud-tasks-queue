<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use function Safe\json_encode;

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

    public function addEvent(string $status, array $additional = []): void
    {
        $event = [
            'status' => $status,
            'datetime' => now()->utc()->toDateTimeString(),
        ];

        $this->events[] = array_merge($additional, $event);
    }

    public function toArray(): array
    {
        return [
            'events' => $this->events,
            'payload' => $this->payload,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function createFromArray(array $data): TaskMetadata
    {
        $metadata = new TaskMetadata();

        $metadata->events = $data['events'];
        $metadata->payload = $data['payload'];

        return $metadata;
    }
}
