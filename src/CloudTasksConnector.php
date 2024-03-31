<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

class CloudTasksConnector implements ConnectorInterface
{
    public function connect(array $config): CloudTasksQueue
    {
        return new CloudTasksQueue(
            config: $config,
            client: app(CloudTasksClient::class),
            dispatchAfterCommit: $config['after_commit'] ?? null
        );
    }
}
