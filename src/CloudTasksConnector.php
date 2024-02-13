<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

class CloudTasksConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        // The handler is the URL which Cloud Tasks will call with the job payload. This
        // URL of the handler can be manually set through an environment variable, but
        // if it is not then we will choose a sensible default (the current app url)
        if (empty($config['handler'])) {
            // At this point (during service provider boot) the trusted proxy middleware
            // has not been set up, and so we are not ready to get the scheme and host
            // So we wrap it and get it later, after the middleware has been set up.
            $config['handler'] = function () {
                return request()->getSchemeAndHttpHost();
            };
        }

        return new CloudTasksQueue($config, app(CloudTasksClient::class), $config['after_commit'] ?? null);
    }
}
