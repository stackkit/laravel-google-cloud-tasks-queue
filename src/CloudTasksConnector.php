<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * @phpstan-type QueueConfig array{
 *     driver: string,
 *     project: string,
 *     location: string,
 *     queue: string,
 *     app_engine?: bool,
 *     app_engine_service?: string,
 *     handler?: string,
 *     service_account_email?: string,
 *     backoff?: int,
 *     dispatch_deadline?: int,
 *     after_commit?: bool,
 *     cloud_run_job?: bool,
 *     cloud_run_job_name?: string,
 *     cloud_run_job_region?: string,
 *     payload_disk?: string,
 *     payload_prefix?: string,
 *     payload_threshold?: int
 * }
 */
class CloudTasksConnector implements ConnectorInterface
{
    /**
     * @param  QueueConfig  $config
     */
    public function connect(array $config): CloudTasksQueue
    {
        return new CloudTasksQueue(
            config: $config,
            client: app(CloudTasksClient::class),
            dispatchAfterCommit: $config['after_commit'] ?? null
        );
    }
}
