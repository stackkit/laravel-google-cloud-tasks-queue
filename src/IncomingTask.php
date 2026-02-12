<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Error;
use Exception;

use function Safe\json_decode;

use Safe\Exceptions\JsonException;
use Illuminate\Contracts\Encryption\Encrypter;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;

/**
 * @phpstan-import-type JobShape from CloudTasksJob
 * @phpstan-import-type QueueConfig from CloudTasksConnector
 *
 * @phpstan-type JobCommand array{
 *     queue?: ?string,
 *     connection?: ?string
 * }
 */
class IncomingTask
{
    /**
     * @param  JobShape  $task
     */
    private function __construct(
        private readonly array $task,
        private readonly ?string $taskName = null
    ) {
        //
    }

    public static function fromJson(string $payload, ?string $taskName = null): self
    {
        try {
            $decode = json_decode($payload, true);

            if (! is_array($decode)) {
                throw new Exception('Invalid task payload.');
            }

            /** @var JobShape $decode */
            return new self($decode, $taskName);
        } catch (JsonException) {
            throw new Exception('Invalid task payload.');
        }
    }

    public function connection(): string
    {
        $command = $this->command();

        return $command['connection']
            ?? config()->string('queue.default');
    }

    public function queue(): string
    {
        $command = $this->command();

        return $command['queue']
            ?? config()->string('queue.connections.'.$this->connection().'.queue');
    }

    public function shortTaskName(): string
    {
        // When running via CLI (Cloud Run Job), use the task name passed to constructor
        if ($this->taskName !== null) {
            return $this->taskName;
        }

        // When running via HTTP, extract from headers
        return request()->header('X-CloudTasks-TaskName')
            ?? request()->header('X-AppEngine-TaskName')
            ?? throw new Error('Unable to extract taskname from header');
    }

    public function fullyQualifiedTaskName(): string
    {
        /** @var QueueConfig $config */
        $config = config('queue.connections.'.$this->connection());

        return CloudTasksClient::taskName(
            project: $config['project'],
            location: $config['location'],
            queue: $this->queue(),
            task: $this->shortTaskName(),
        );
    }

    /**
     * @return JobCommand
     */
    public function command(): array
    {
        $command = $this->task['data']['command'];

        if (str_starts_with($command, 'O:')) {
            // @phpstan-ignore-next-line
            return (array) unserialize($command, ['allowed_classes' => false]);
        }

        if (app()->bound(Encrypter::class)) {
            // @phpstan-ignore-next-line
            return (array) unserialize(app(Encrypter::class)->decrypt($command));
        }

        return [];
    }

    /**
     * @return JobShape
     */
    public function toArray(): array
    {
        return $this->task;
    }
}
