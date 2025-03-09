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
 *     after_commit?: bool
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
