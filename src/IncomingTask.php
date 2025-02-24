<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Error;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Contracts\Encryption\Encrypter;
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
            $decode = json_decode($payload, true);

            return new self(is_array($decode) ? $decode : []);
        } catch (JsonException) {
            return new self([]);
        }
    }

    public function isInvalid(): bool
    {
        return $this->task === [];
    }

    public function connection(): string
    {
        if ($connection = data_get($this->command(), 'connection')) {
            return $connection;
        }

        return config('queue.default');
    }

    public function queue(): string
    {
        if ($queue = data_get($this->command(), 'queue')) {
            return $queue;
        }

        return config('queue.connections.'.$this->connection().'.queue');
    }

    public function shortTaskName(): string
    {
        return request()->header('X-CloudTasks-TaskName')
            ?? request()->header('X-AppEngine-TaskName')
            ?? throw new Error('Unable to extract taskname from header');
    }

    public function fullyQualifiedTaskName(): string
    {
        $config = config('queue.connections.'.$this->connection());

        return CloudTasksClient::taskName(
            project: $config['project'],
            location: $config['location'],
            queue: $this->queue(),
            task: $this->shortTaskName(),
        );
    }

    public function command(): array
    {
        $command = $this->task['data']['command'];

        if (str_starts_with($command, 'O:')) {
            return (array) unserialize($command, ['allowed_classes' => false]);
        }

        if (app()->bound(Encrypter::class)) {
            return (array) unserialize(app(Encrypter::class)->decrypt($command));
        }

        return [];
    }

    public function toArray(): array
    {
        return $this->task;
    }
}
