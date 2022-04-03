<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Stackkit\LaravelGoogleCloudTasksQueue\Entities\StatRow;
use const STR_PAD_LEFT;

class CloudTasksApiController
{
    public function login(): ?string
    {
        $password = config('cloud-tasks.dashboard.password');

        if (!is_string($password)) {
            return null;
        }

        $validPassword = hash_equals($password, request('password'));

        if (!$validPassword) {
            return null;
        }

        return encrypt(Carbon::now()->getTimestamp() + 900);
    }

    public function dashboard(): array
    {
        $dbDriver = config('database.connections.' . config('database.default') . '.driver');

        if (!in_array($dbDriver, ['mysql', 'pgsql'])) {
            throw new Exception('Unsupported database driver for Cloud Tasks dashboard.');
        }

        $groupBy = [
            'mysql' => [
                'this_minute' => 'DATE_FORMAT(created_at, \'%H:%i\')',
                'this_hour' => 'DATE_FORMAT(created_at, \'%H\')',
            ],
            'pgsql' => [
                'this_minute' => 'TO_CHAR(created_at :: TIME, \'HH24:MI\')',
                'this_hour' => 'TO_CHAR(created_at :: TIME, \'HH24\')',
            ],
        ][$dbDriver];

        /**
         * @var array<StatRow> $stats
         */
        $stats = DB::table((new StackkitCloudTask())->getTable())
            ->where('created_at', '>=', now()->utc()->startOfDay())
            ->select(
                [
                    DB::raw('COUNT(id) as count'),
                    DB::raw('CASE WHEN status = \'failed\' THEN 1 ELSE 0 END AS failed'),
                    DB::raw('
                        CASE
                            WHEN ' . $groupBy['this_minute'] . ' = \'' . now()->utc()->format('H:i') . '\' THEN \'this_minute\'
                            WHEN ' . $groupBy['this_hour'] . ' = \'' . now()->utc()->format('H') . '\' THEN \'this_hour\'
                            
                            ELSE \'today\'
                        END AS time_preset                            
                    ')
                ]
            )
            ->groupBy(
                [
                    'failed',
                    'time_preset',
                ]
            )
            ->get()
            ->map(fn($row) => StatRow::createFromObject($row))
            ->toArray();

        $response = [
            'recent' => [
                'this_minute' => 0,
                'this_hour' => 0,
                'this_day' => 0,
            ],
            'failed' => [
                'this_minute' => 0,
                'this_hour' => 0,
                'this_day' => 0,
            ],
        ];

        foreach ($stats as $row) {
            $response['recent']['this_day'] += $row->count;

            if ($row->time_preset === 'this_minute') {
                $response['recent']['this_minute'] += $row->count;
                $response['recent']['this_hour'] += $row->count;
            }

            if ($row->time_preset === 'this_hour') {
                $response['recent']['this_hour'] += $row->count;
            }

            if ($row->failed === 0) {
                continue;
            }

            $response['failed']['this_day'] += $row->count;

            if ($row->time_preset === 'this_minute') {
                $response['failed']['this_minute'] += $row->count;
                $response['failed']['this_hour'] += $row->count;
            }

            if ($row->time_preset === 'this_hour') {
                $response['failed']['this_hour'] += $row->count;
            }
        }

        return $response;
    }

    /**
     * @return Collection<int, StackkitCloudTask>
     */
    public function tasks()
    {
        Carbon::setTestNowAndTimezone(now()->utc());

        $tasks = StackkitCloudTask::query()
            ->newestFirst()
            ->where('created_at', '>=', now()->utc()->startOfDay())
            ->when(request('filter') === 'failed', function (Builder $builder) {
                return $builder->where('status', 'failed');
            })
            ->when(request('time'), function (Builder $builder) {
                [$hour, $minute] = explode(':', request('time'));

                return $builder
                    ->where('created_at', '>=', now()->setTime((int) $hour, (int) $minute, 0))
                    ->where('created_at', '<=', now()->setTime((int) $hour, (int) $minute, 59));
            })
            ->when(request('hour'), function (Builder $builder, $hour) {
                return $builder->where('created_at', '>=', now()->setTime((int) $hour, 0, 0))
                    ->where('created_at', '<=', now()->setTime((int) $hour, 59, 59));
            })
            ->when(request('queue'), function (Builder $builder, $queue) {
                return $builder->where('queue', $queue);
            })
            ->when(request('status'), function (Builder $builder, $status) {
                return $builder->where('status', $status);
            })
            ->limit(100)
            ->get();

        $maxId = $tasks->max('id');

        return $tasks->map(function (StackkitCloudTask $task) use ($maxId)
        {
            return [
                'uuid' => $task->task_uuid,
                'id' => str_pad((string) $task->id, strlen($maxId), '0', STR_PAD_LEFT),
                'name' => $task->name,
                'status' => $task->status,
                'attempts' => $task->getNumberOfAttempts(),
                'created' => $task->created_at ? $task->created_at->diffForHumans() : null,
                'queue' => $task->queue,
            ];
        });
    }

    public function task(string $uuid): array
    {
        $task = StackkitCloudTask::findByUuid($uuid);

        return [
            'id' => $task->id,
            'status' => $task->status,
            'queue' => $task->queue,
            'events' => $task->getEvents(),
            'payload' => $task->getPayloadPretty(),
            'exception' => $task->getMetadata()['exception'] ?? null,
        ];
    }
}
