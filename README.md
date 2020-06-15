# Introduction

This package allows you to use Google Cloud Tasks as your queue driver.

# How it works [!]

!! Please read this next section !!

You may already know this, but using Google Cloud Tasks is fundamentally different than typical Laravel queues.

Typically a Laravel queue has a worker that listens to incoming jobs using the `queue:work` / `queue:listen` command.
With Cloud Tasks, this is not the case. Instead, Cloud Tasks will schedule the job for you and make an HTTP request to your application with the job payload. There is no need to run a `queue:work/listen` command.

Please read the following resource on how to correctly configure your queue so your application doesn't get overloaded with queue request:

https://cloud.google.com/tasks/docs/configuring-queues

This package uses the HTTP request handler and doesnt' support AppEngine yet. But feel free to contribute! I myself don't use AppEngine.

# Installation

(1) Require the package using Composer

```bash
composer require stackkit/laravel-google-cloud-tasks-queue
```

(2) Create a new Cloud Tasks queue using `gcloud`

````bash
gcloud tasks queues create [QUEUE_ID]
````

[Official documentation - Creating Cloud Tasks queues](https://cloud.google.com/tasks/docs/creating-queues)

(3) Add a new queue connection to `config/queue.php`

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

(4) Update the `QUEUE_CONNECTION` environment variable

```
QUEUE_CONNECTION=cloudtasks
```
