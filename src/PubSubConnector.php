<?php

namespace Stackkit\LaravelGooglePubSubQueue;

use Google\Cloud\PubSub\PubSubClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

class PubSubConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        return new PubSubQueue(
            new PubSubClient($config),
            $config['queue'] ,
            $config['subscriber']
        );
    }
}