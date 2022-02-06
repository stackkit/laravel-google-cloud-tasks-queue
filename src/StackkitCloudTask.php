<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use const JSON_PRETTY_PRINT;

class StackkitCloudTask extends Model
{
    protected $guarded = [];

    public function scopeNewestFirst($builder)
    {
        return $builder->orderByDesc('created_at');
    }

    public function scopeFailed($builder)
    {
        return $builder->whereStatus('failed');
    }

    public function getMetadata()
    {
        $value = $this->metadata;

        if (is_null($value)) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return int
     */
    public function getNumberOfAttempts()
    {
        $events = Arr::get($this->getMetadata(), 'events', []);

        return count(array_filter($events, function (array $event) {
            return in_array(
                $event['status'],
                ['running']
            );
        }));
    }

    public function setMetadata($key, $value)
    {
        $metadata = $this->getMetadata();

        Arr::set($metadata, $key, $value);

        $this->metadata = json_encode($metadata);
    }

    public function incrementAttempts()
    {
        //
    }

    public function getEvents()
    {
        Carbon::setTestNowAndTimezone(now()->utc());

        $events = Arr::get($this->getMetadata(), 'events', []);

        return array_map(function (array $event) {
            $event['diff'] = Carbon::parse($event['datetime'])->diffForHumans();
            return $event;
        }, $events);
    }

    public function getPayloadPretty()
    {
        $payload = $this->getMetadata()['payload'] ?? '[]';

        return json_encode(
            json_decode($payload),
            JSON_PRETTY_PRINT
        );
    }
}