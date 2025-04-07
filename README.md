# Cloud Tasks queue driver for Laravel

[![Run tests](https://github.com/stackkit/laravel-google-cloud-tasks-queue/actions/workflows/run-tests.yml/badge.svg)](https://github.com/stackkit/laravel-google-cloud-tasks-queue/actions/workflows/run-tests.yml)
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/downloads.svg" alt="Downloads"></a>

This package allows Google Cloud Tasks to be used as the queue driver.

<sub>Companion packages: <a href="https://github.com/stackkit/laravel-google-cloud-scheduler">Cloud Scheduler</a>, <a href="https://github.com/marickvantuil/laravel-google-cloud-logging">Cloud Logging</a></sub>

![Image](https://github.com/user-attachments/assets/d9af0938-43b7-407b-8791-83419420a62b)



### Requirements

This package requires Laravel 11 or 12.

### Installation

Require the package using Composer

```shell
composer require stackkit/laravel-google-cloud-tasks-queue
```

Add a new queue connection to `config/queue.php`

```php
'cloudtasks' => [
  'driver' => 'cloudtasks',
  'project' => env('CLOUD_TASKS_PROJECT', ''),
  'location' => env('CLOUD_TASKS_LOCATION', ''),
  'queue' => env('CLOUD_TASKS_QUEUE', 'default'),
  
  // Required when using AppEngine
  'app_engine'            => env('APP_ENGINE_TASK', false),
  'app_engine_service'    => env('APP_ENGINE_SERVICE', ''),
  
  // Required when not using AppEngine
  'handler'               => env('CLOUD_TASKS_HANDLER', ''),
  'service_account_email' => env('CLOUD_TASKS_SERVICE_EMAIL', ''),
  
  'backoff' => 0,
  'after_commit' => false,
  // enable this if you want to set a non-default Google Cloud Tasks dispatch timeout
  //'dispatch_deadline' => 1800, // in seconds
],
```

Finally, set the correct environment variables.

```dotenv
QUEUE_CONNECTION=cloudtasks
```

If you're using Cloud Run:

```dotenv
CLOUD_TASKS_PROJECT=my-project
CLOUD_TASKS_LOCATION=europe-west6
CLOUD_TASKS_QUEUE=barbequeue
CLOUD_TASKS_SERVICE_EMAIL=my-service-account@appspot.gserviceaccount.com
# Optionally (when using a separate task handler):
CLOUD_TASKS_SERVICE_HANDLER=
```

If you're using App Engine:

```dotenv
CLOUD_TASKS_PROJECT=my-project
CLOUD_TASKS_LOCATION=europe-west6
CLOUD_TASKS_QUEUE=barbequeue
APP_ENGINE_TASK=true
APP_ENGINE_SERVICE=my-service
```

Please check the table below on what the values mean and what their value should be.

| Environment variable                              | Description                                                                                                                                              | Example                                          
---------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------
| `CLOUD_TASKS_PROJECT`                    | The project your queue belongs to.                                                                                                                       | `my-project`                                     
| `CLOUD_TASKS_LOCATION`                   | The region where the project is hosted.                                                                                                                  | `europe-west6`                                   
| `CLOUD_TASKS_QUEUE`                      | The default queue a job will be added to.                                                                                                                | `emails`                                         
| **App Engine**                                    
| `APP_ENGINE_TASK` (optional)             | Set to true to use App Engine task (else a Http task will be used). Defaults to false.                                                                   | `true`                                           
| `APP_ENGINE_SERVICE` (optional)          | The App Engine service to handle the task (only if using App Engine task).                                                                               | `api`                                            
| **Non- App Engine apps**                          
| `CLOUD_TASKS_SERVICE_EMAIL`   (optional) | The email address of the service account. Important, it should have the correct roles. See the section below which roles.                                | `my-service-account@appspot.gserviceaccount.com` 
| `CLOUD_TASKS_HANDLER` (optional)         | The URL that Cloud Tasks will call to process a job. This should be the URL to your Laravel app. By default we will use the URL that dispatched the job. | `https://<your website>.com`                     

</details>

Optionally, you may publish the config file:

```console
php artisan vendor:publish --tag=cloud-tasks
```

If you are using separate services for dispatching and handling tasks, and your application only dispatches jobs and should not be able to handle jobs, you may disable the task handler from `config/cloud-tasks.php`:

```php
'disable_task_handler' => env('CLOUD_TASKS_DISABLE_TASK_HANDLER', false),
```

### How to

#### Passing headers to a task

You can pass headers to a task by using the `setTaskHeadersUsing` method on the `CloudTasksQueue` class.

```php
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

CloudTasksQueue::setTaskHeadersUsing(static fn() => [
  'X-My-Header' => 'My-Value',
]);
```

If necessary, the current payload being dispatched is also available:

```php
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

CloudTasksQueue::setTaskHeadersUsing(static fn(array $payload) => [
  'X-My-Header' => $payload['displayName'],
]);
```

#### Configure task handler url

You can set the handler url for a task by using the `configureHandlerUrlUsing` method on the `CloudTasksQueue` class.

```php
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

CloudTasksQueue::configureHandlerUrlUsing(static fn() => 'https://example.com/my-url');
```

If necessary, the current job being dispatched is also available:

```php
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

CloudTasksQueue::configureHandlerUrlUsing(static fn(MyJob $job) => 'https://example.com/my-url/' . $job->something());
```

#### Configure worker options

You can configure worker options by using the `configureWorkerOptionsUsing` method on the `CloudTasksQueue` class.

```php
use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;

CloudTasksQueue::configureWorkerOptionsUsing(function (IncomingTask $task) {
    $queueTries = [
        'high' => 5,
        'low' => 1,
    ];

    return new WorkerOptions(maxTries: $queueTries[$task->queue()] ?? 1);
});
```

#### Use a custom credentials file

Modify (or add) the `client_options` key in the `config/cloud-tasks.php` file:

```php
'client_options' => [
    'credentials' => '/path/to/credentials.json',
]
```


#### Modify CloudTasksClient options

Modify (or add) the `client_options` key in the `config/cloud-tasks.php` file:

```php
'client_options' => [
    // custom options here
]
```

### How it works and differences

Using Cloud Tasks as a Laravel queue driver is fundamentally different than other Laravel queue drivers, like Redis.

Typically a Laravel queue has a worker that listens to incoming jobs using the `queue:work` / `queue:listen` command.
With Cloud Tasks, this is not the case. Instead, Cloud Tasks will schedule the job for you and make an HTTP request to
your application with the job payload. There is no need to run a `queue:work/listen` command.

#### Good to know

Cloud Tasks has it's own retry configuration options: maximum number of attempts, retry duration, min/max backoff and max doublings. All of these options are ignored by this package. Instead, you may configure max attempts, retry duration and backoff strategy right from Laravel.

### Authentication

If you're not using your master service account (which has all abilities), you must add the following roles to make it
works:

1. App Engine Viewer
2. Cloud Tasks Enqueuer
3. Cloud Tasks Viewer
4. Cloud Tasks Task Deleter
5. Service Account User

### Upgrading

Read [UPGRADING.MD](UPGRADING.md) on how to update versions.

### Troubleshooting

#### HttpRequest.url must start with 'https://'

This can happen when your application runs behind a reverse proxy. To fix this, add the application domain to Laravel's [trusted proxies](https://laravel.com/docs/11.x/requests#trusting-all-proxies). You may need to add the wildcard `*` as trusted proxy.

#### Maximum call stack size (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?

This currently seems to be a bug with PHP 8.3 and `googleapis/gax-php`. See [this issue](https://github.com/googleapis/gax-php/issues/584) for more information.

A potential workaround is to disable PHP 8.3 call stack limit by setting this value in `php.ini`:

```ini
zend.max_allowed_stack_size: -1
```

### Contributing

You can use the services defined in `docker-compose.yml` to start running the package.

Inside the container, run `composer install`. 

Set up the environment: `cp .env.example .env`

Some tests hit the Cloud Tasks API and need a project and key to be able to hit it. See the variables in `.env`
