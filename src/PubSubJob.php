<?php

namespace Stackkit\LaravelGooglePubSubQueue;

use Google\Cloud\PubSub\Message;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Stackkit\LaravelGooglePubSubQueue\PubSubQueue;

class PubSubJob extends Job implements JobContract
{
    /**
     * The PubSub queue.
     *
     * @var \Stackkit\LaravelGooglePubSubQueue\PubSubQueue
     */
    protected $pubsub;

    /**
     * The job instance.
     *
     * @var array
     */
    protected $job;

    /**
     * Create a new job instance.
     *
     * @param \Illuminate\Container\Container $container
     * @param \Stackkit\LaravelGooglePubSubQueue\PubSubQueue $sqs
     * @param \Google\Cloud\PubSub\Message $job
     * @param string       $connectionName
     * @param string       $queue
     */
    public function __construct(Container $container, PubSubQueue $pubsub, Message $job, $connectionName, $queue)
    {
        $this->pubsub = $pubsub;
        $this->job = $job;
        $this->queue = $queue;
        $this->container = $container;
        $this->connectionName = $connectionName;

        $this->decoded = $this->payload();
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return $this->decoded['id'] ?? null;
    }

    /**
     * Get the raw body of the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return base64_decode($this->job->data());
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        return ((int) $this->job->attribute('attempts') ?? 0) + 1;
    }

    /**
     * Release the job back into the queue.
     *
     * @param  int   $delay
     * @return void
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $attempts = $this->attempts();
        $this->pubsub->republish(
            $this->job,
            $this->queue,
            ['attempts' => (string) $attempts],
            $delay
        );
    }
}