<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use const JSON_PRETTY_PRINT;
use function Safe\json_encode;
use function Safe\json_decode;

/**
 * @property int $id
 * @property string $queue
 * @property string $task_uuid
 * @property string $name
 * @property string $status
 * @property string|null $metadata
 * @property string|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StackkitCloudTask extends Model
{
    protected $guarded = [];

    public static function findByUuid(string $uuid): StackkitCloudTask
    {
        return self::whereTaskUuid($uuid)->firstOrFail();
    }

    /**
     * @param Builder<StackkitCloudTask> $builder
     * @return Builder<StackkitCloudTask>
     */
    public function scopeNewestFirst(Builder $builder): Builder
    {
        return $builder->orderByDesc('created_at');
    }

    /**
     * @param Builder<StackkitCloudTask> $builder
     * @return Builder<StackkitCloudTask>
     */
    public function scopeFailed(Builder $builder): Builder
    {
        return $builder->whereStatus('failed');
    }

    public function getMetadata(): array
    {
        $value = $this->metadata;

        if (is_null($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getNumberOfAttempts(): int
    {
        return collect($this->getEvents())
            ->where('status', 'running')
            ->count();
    }

    /**
     * @param mixed $value
     */
    public function setMetadata(string $key, $value): void
    {
        $metadata = $this->getMetadata();

        Arr::set($metadata, $key, $value);

        $this->metadata = json_encode($metadata);
    }

    public function addMetadataEvent(array $event): void
    {
        $metadata = $this->getMetadata();

        $metadata['events'] ??= [];

        $metadata['events'][] = $event;

        $this->metadata = json_encode($metadata);
    }

    public function getEvents(): array
    {
        Carbon::setTestNowAndTimezone(now()->utc());

        /** @var array $events */
        $events = Arr::get($this->getMetadata(), 'events', []);

        return collect($events)->map(function ($event) {
            /** @var array $event */
            $event['diff'] = Carbon::parse($event['datetime'])->diffForHumans();
            return $event;
        })->toArray();
    }

    public function getPayloadPretty(): string
    {
        $payload = $this->getMetadata()['payload'] ?? '[]';

        return json_encode(
            json_decode($payload),
            JSON_PRETTY_PRINT
        );
    }
}
