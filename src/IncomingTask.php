<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Safe\Exceptions\JsonException;
use function Safe\json_decode;

class IncomingTask
{
    private function __construct(private readonly array $task)
    {
        //
    }

    public static function fromJson(string $payload): self
    {
        try {
            $decode = json_decode($payload, assoc: true);

            return new self(is_array($decode) ? $decode : []);
        } catch (JsonException) {
            return new self([]);
        }
    }

    public function isEmpty(): bool
    {
        return $this->task === [];
    }

    public function connection(): string
    {
        return $this->task['internal']['connection'];
    }

    public function queue(): string
    {
        return $this->task['internal']['queue'];
    }

    public function taskName(): string
    {
        return $this->task['internal']['taskName'];
    }

    public function toArray(): array
    {
        return $this->task;
    }
}
