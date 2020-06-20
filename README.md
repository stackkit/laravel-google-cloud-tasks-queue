<p align="center">
  <img src="/logo.png" width="400">
</p>
<p align="center">
<img src="https://github.com/stackkit/laravel-google-cloud-tasks-queue/workflows/Run%20tests/badge.svg?branch=master" alt="Build Status">
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/license.svg" alt="License"></a>
</p>

# Introduction

This package allows you to use Google Cloud Tasks as your queue driver.

# How it works

Using Cloud Tasks as a Laravel queue driver is fundamentally different than other Laravel queue drivers, like Redis.

Typically a Laravel queue has a worker that listens to incoming jobs using the `queue:work` / `queue:listen` command.
With Cloud Tasks, this is not the case. Instead, Cloud Tasks will schedule the job for you and make an HTTP request to your application with the job payload. There is no need to run a `queue:work/listen` command.

For more information on how to configure the Cloud Tasks queue, read the next section [Configuring the queue](#configuring-the-queue)

This package uses the HTTP request handler and doesn't support AppEngine. But feel free to contribute!

# Requirements

This package requires Laravel 5.6 or higher.

Please check the table below for supported Laravel and PHP versions:

|Laravel Version| PHP Version |
|---|---|
| 5.6 | 7.2 or 7.3
| 5.7 | 7.2 or 7.3
| 5.8 | 7.2 or 7.3 or 7.4
| 6.x | 7.2 or 7.3 or 7.4
| 7.x | 7.2 or 7.3 or 7.4

# Installation

(1) Require the package using Composer

```bash
composer require stackkit/laravel-google-cloud-tasks-queue
```


[Official documentation - Creating Cloud Tasks queues](https://cloud.google.com/tasks/docs/creating-queues)

(2) Add a new queue connection to `config/queue.php`

```
'cloudtasks' => [
    'driver' => 'cloudtasks',
    'credentials' => base_path('gcloud-key.json'),
    'project' => env('STACKKIT_CLOUD_TASKS_PROJECT', ''),
    'location' => env('STACKKIT_CLOUD_TASKS_LOCATION', ''),
    'handler' => env('STACKKIT_CLOUD_TASKS_HANDLER', ''),
    'queue' => env('STACKKIT_CLOUD_TASKS_QUEUE', 'default'),
    'service_account_email' => env('STACKKIT_CLOUD_TASKS_SERVICE_EMAIL', ''),
],
```

(3) Update the `QUEUE_CONNECTION` environment variable

```
QUEUE_CONNECTION=cloudtasks
```

(4) Create a new Cloud Tasks queue using `gcloud`

````bash
gcloud tasks queues create [QUEUE_ID]
````

Now that the package is installed, the final step is to set the correct environment variables.

Please check the table below on what the values mean and what their value should be.

|Environment variable|Description|Example
|---|---|---
|`STACKKIT_CLOUD_TASKS_PROJECT`|The project your queue belongs to.|`my-project`
|`STACKKIT_CLOUD_TASKS_LOCATION`|The region where the AppEngine is hosted|`europe-west6`
|`STACKKIT_CLOUD_TASKS_HANDLER`|The URL that Cloud Tasks will call to process a job. This should be the URL to your Laravel app with the `handle-task` path added|`https://<your website>.com/handle-task`
|`STACKKIT_CLOUD_TASKS_QUEUE`|The queue a job will be added to|`emails`
|`STACKKIT_CLOUD_TASKS_SERVICE_EMAIL`|The email address of the AppEngine service account. Important, it should have the *Cloud Tasks Enqueuer* role. This is used for securing the handler.|`my-service-account@appspot.gserviceaccount.com`

# Configuring the queue

When you first create a queue using `gcloud tasks queues create`, the default settings will look something like this:

```
rateLimits:
  maxBurstSize: 100
  maxConcurrentDispatches: 1000
  maxDispatchesPerSecond: 500.0
retryConfig:
  maxAttempts: 100
  maxBackoff: 3600s
  maxDoublings: 16
  minBackoff: 0.100s
```

## Configurable settings

### maxBurstSize

Max burst size limits how fast tasks in queue are processed when many tasks are in the queue and the rate is high.

### maxConcurrentDispatches

The maximum number of concurrent tasks that Cloud Tasks allows to be dispatched for this queue

### maxDispatchesPerSecond

The maximum rate at which tasks are dispatched from this queue.

### maxAttempts

Number of attempts per task. Cloud Tasks will attempt the task max_attempts times (that is, if the first attempt fails, then there will be max_attempts - 1 retries). Must be >= -1.|

### maxBackoff

A task will be scheduled for retry between min_backoff and max_backoff duration after it fails

### maxDoublings

The time between retries will double max_doublings times.

A task's retry interval starts at min_backoff, then doubles max_doublings times, then increases linearly, and finally retries retries at intervals of max_backoff up to max_attempts times.
              
For example, if min_backoff is 10s, max_backoff is 300s, and max_doublings is 3, then the a task will first be retried in 10s. The retry interval will double three times, and then increase linearly by 2^3 * 10s. Finally, the task will retry at intervals of max_backoff until the task has been attempted max_attempts times. Thus, the requests will retry at 10s, 20s, 40s, 80s, 160s, 240s, 300s, 300s, ....

## Recommended settings for Laravel

To simulate a single `queue:work/queue:listen` process, simply set the `maxConcurrentDispatches` to 1:

```
gcloud tasks queues update [QUEUE_ID] --max-concurrent-dispatches=1
```

More information on configuring queues:

https://cloud.google.com/tasks/docs/configuring-queues

# Security

The job handler requires each request to have an OpenID token. In the installation step we set the service account email, and with that service account, Cloud Tasks will generate an OpenID token and send it along with the job payload to the handler.

This package verifies that the token is digitally signed by Google. Only Google Tasks will be able to call your handler.

More information about OpenID Connect:

https://developers.google.com/identity/protocols/oauth2/openid-connect