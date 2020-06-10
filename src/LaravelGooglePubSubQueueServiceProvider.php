<?php

namespace Stackkit\LaravelGooglePubSubQueue;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Google\Cloud\PubSub\PubSubClient;

class LaravelGooglePubSubQueueServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['queue']->addConnector('pubsub', function () {
            return new PubSubConnector();
        });

        Route::post('pubsub-wake', function () {
            $client = new PubSubClient([
                "driver" => "pubsub",
                "connection" => "default",
                "queue" => "projects/test-marick/topics/geencijfer-prd-queue",
                "project_id" => "test-marick",
                "retries" => 3,
                "request_timeout" => 60,
                "subscriber" => "projects/test-marick/subscriptions/geencijfer-prd-queue-subscription",
                "keyFilePath" => "/var/www/gcloud-key.json",
            ]);

            $message = $client->consume(request()->toArray());

            logger(base64_decode($message->data()));
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
//        $this->commands([
//            SendEmailsCommand::class,
//        ]);
    }
}
