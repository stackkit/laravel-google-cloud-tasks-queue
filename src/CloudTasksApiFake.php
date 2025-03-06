<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Closure;
use PHPUnit\Framework\Assert;
use Google\Cloud\Tasks\V2\Task;

class CloudTasksApiFake implements CloudTasksApiContract
{
    /**
     * @var array<int, array{
     *     queueName: string,
     *     task: Task
     * }>
     */
    public array $createdTasks = [];

    /**
     * @var array<int, string>
     */
    public array $deletedTasks = [];

    public function createTask(string $queueName, Task $task): Task
    {
        $this->createdTasks[] = compact('queueName', 'task');

        return $task;
    }

    public function deleteTask(string $taskName): void
    {
        $this->deletedTasks[] = $taskName;
    }

    public function getTask(string $taskName): Task
    {
        return (new Task)->setName($taskName);
    }

    public function exists(string $taskName): bool
    {
        foreach ($this->createdTasks as $createdTask) {
            if ($createdTask['task']->getName() === $taskName) {
                return ! in_array($taskName, $this->deletedTasks);
            }
        }

        return false;
    }

    public function assertTaskDeleted(string $taskName): void
    {
        Assert::assertTrue(
            in_array($taskName, $this->deletedTasks),
            'The task ['.$taskName.'] should have been deleted but it is not.'
        );
    }

    public function assertTaskNotDeleted(string $taskName): void
    {
        Assert::assertTrue(
            ! in_array($taskName, $this->deletedTasks),
            'The task ['.$taskName.'] should not have been deleted but it was.'
        );
    }

    public function assertDeletedTaskCount(int $count): void
    {
        Assert::assertCount($count, $this->deletedTasks);
    }

    public function assertTaskCreated(Closure $closure): void
    {
        $count = count(array_filter($this->createdTasks, function ($createdTask) use ($closure) {
            return $closure($createdTask['task'], $createdTask['queueName']);
        }));

        Assert::assertTrue($count > 0, 'Task was not created.');
    }

    public function assertCreatedTaskCount(int $count): void
    {
        Assert::assertCount($count, $this->createdTasks);
    }
}
