<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

class CloudTasksConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        Config::validate($config);

        // @todo - clean this up
        if (app()->has(CloudTasksClient::class)) {
            $client = app(CloudTasksClient::class);
        } else {
            $client = new CloudTasksClient($this->buildConfig());
        }

        return new CloudTasksQueue($config, $client);
    }

    private function buildConfig()
    {
        return [
            'credentials' => Config::credentials(),
        ];
    }
}
