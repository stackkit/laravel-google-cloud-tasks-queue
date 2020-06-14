<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

class CloudTasksConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        return new CloudTasksQueue(
            $config,
            new CloudTasksClient($this->buildConfig())
        );
    }

    private function buildConfig()
    {
        return [
            'credentials' => Config::credentials(),
        ];
    }
}
