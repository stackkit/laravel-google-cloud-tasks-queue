<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Queue\Connectors\ConnectorInterface;

class CloudTasksConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        return new CloudTasksQueue();
    }
}
