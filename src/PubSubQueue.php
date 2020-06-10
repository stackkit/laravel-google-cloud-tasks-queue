<?php

namespace Stackkit\LaravelGooglePubSubQueue;

use Google\Cloud\PubSub\Message;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\PubSub\Topic;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;

class PubSubQueue extends Queue implements QueueContract
{
    /**
     * The PubSubClient instance.
     *
     * @var \Google\Cloud\PubSub\PubSubClient
     */
    protected $pubsub;

    /**
     * Default queue name.
     *
     * @var string
     */
    protected $default;

    /**
     * Default subscriber.
     *
     * @var string
     */
    protected $subscriber;

    /**
     * Create a new GCP PubSub instance.
     *
     * @param \Google\Cloud\PubSub\PubSubClient $pubsub
     * @param string $default
     */
    public function __construct(PubSubClient $pubsub, $default, $subscriber)
    {
        $this->pubsub = $pubsub;
        $this->default = $default;
        $this->subscriber = $subscriber;
    }

    public function size($queue = null)
    {
        // doesn't exist in PubSub
        return 0;
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $this->getQueue($queue), $data), $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $topic = $this->getTopic($queue, true);

        $this->subscribeToTopic($topic);

        $publish = ['data' => base64_encode($payload)];

        if (! empty($options)) {
            $publish['attributes'] = $this->validateMessageAttributes($options);
        }

        $topic->publish($publish);

        $decoded_payload = json_decode($payload, true);

        return $decoded_payload['id'];
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw(
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            ['available_at' => (string) $this->availableAt($delay)]
        );
    }

    public function pop($queue = null)
    {
        $topic = $this->getTopic($this->getQueue($queue));

        if (! $topic->exists()) {
            return null;
        }

        $subscription = $topic->subscription($this->getSubscriberName());
        $messages = $subscription->pull([
            'returnImmediately' => true,
            'maxMessages' => 1,
        ]);

        if (empty($messages) || count($messages) < 1) {
            return null;
        }

        $available_at = $messages[0]->attribute('available_at');
        if ($available_at && $available_at > time()) {
            return null;
        }

        $this->acknowledge($messages[0], $queue);

        return new PubSubJob(
            $this->container,
            $this,
            $messages[0],
            $this->connectionName,
            $this->getQueue($queue)
        );
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        $payloads = [];

        foreach ((array) $jobs as $job) {
            $payload = $this->createPayload($job, $this->getQueue($queue), $data);
            $payloads[] = ['data' => base64_encode($payload)];
        }

        $topic = $this->getTopic($this->getQueue($queue));

        $this->subscribeToTopic($topic);

        return $topic->publishBatch($payloads);
    }

    public function acknowledge(Message $message, $queue = null)
    {
        $subscription = $this->getTopic($this->getQueue($queue))->subscription($this->getSubscriberName());
        $subscription->acknowledge($message);
    }

    public function republish(Message $message, $queue = null, $options = [], $delay = 0)
    {
        $topic = $this->getTopic($this->getQueue($queue));

        $options = array_merge([
            'available_at' => (string) $this->availableAt($delay),
        ], $this->validateMessageAttributes($options));

        return $topic->publish([
            'data' => $message->data(),
            'attributes' => $options,
        ]);
    }

    protected function createPayloadArray($job, $queue, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $this->getQueue($queue), $data), [
            'id' => Str::random(32),
        ]);
    }

    private function validateMessageAttributes($attributes): array
    {
        $attributes_values = array_filter($attributes, 'is_string');

        if (count($attributes_values) !== count($attributes)) {
            throw new \UnexpectedValueException('PubSubMessage attributes only accept key-value pairs and all values must be string.');
        }

        $attributes_keys = array_filter(array_keys($attributes), 'is_string');

        if (count($attributes_keys) !== count(array_keys($attributes))) {
            throw new \UnexpectedValueException('PubSubMessage attributes only accept key-value pairs and all keys must be string.');
        }

        return $attributes;
    }

    public function getTopic($queue)
    {
        return $this->pubsub->topic($this->getQueue($queue));
    }

    public function subscribeToTopic(Topic $topic)
    {
        $subscription = $topic->subscription($this->getSubscriberName());

        if (! $subscription->exists()) {
            $subscription = $topic->subscribe($this->getSubscriberName());
        }

        return $subscription;
    }

    public function getSubscriberName()
    {
        return $this->subscriber;
    }

    public function getPubSub()
    {
        return $this->pubsub;
    }

    public function getQueue($queue)
    {
        return $queue ?: $this->default;
    }
}