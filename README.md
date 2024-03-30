<p align="center">
<img src="/assets/logo.png" width="400">
</p>
<p align="center">
<img src="https://github.com/stackkit/laravel-google-cloud-tasks-queue/workflows/Run%20tests/badge.svg?branch=master" alt="Build Status">
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/license.svg" alt="License"></a>
</p>

# Introduction

This package allows Google Cloud Tasks to be used as the queue driver.

<p align="center">
<img src="/assets/cloud-tasks-home.png" width="100%">
</p>

<details>
<summary>
Requirements
</summary>

<br>
This package requires Laravel 10 or higher and supports MySQL 8 and PostgreSQL 15. Might support older database versions too, but package hasn't been tested for it.

Please check the [Laravel support policy](https://laravel.com/docs/master/releases#support-policy) table for supported
Laravel and PHP versions.
</details>
<details>
<summary>Installation</summary>
<br>

Require the package using Composer

```console
composer require stackkit/laravel-google-cloud-tasks-queue
```

Publish the service provider:

```console
php artisan vendor:publish --provider=cloud-tasks
```

Add a new queue connection to `config/queue.php`

```php
'cloudtasks' => [
  'driver' => 'cloudtasks',
  'project' => env('STACKKIT_CLOUD_TASKS_PROJECT', ''),
  'location' => env('STACKKIT_CLOUD_TASKS_LOCATION', ''),
  'queue' => env('STACKKIT_CLOUD_TASKS_QUEUE', 'default'),
  
  // Required when using AppEngine
  'app_engine'            => env('STACKKIT_APP_ENGINE_TASK', false),
  'app_engine_service'    => env('STACKKIT_APP_ENGINE_SERVICE', ''),
  
  // Required when not using AppEngine
  'handler'               => env('STACKKIT_CLOUD_TASKS_HANDLER', ''),
  'service_account_email' => env('STACKKIT_CLOUD_TASKS_SERVICE_EMAIL', ''),
  
  'backoff' => 0,
],
```

If you are using separate services for dispatching and handling tasks, you may want to change the following settings:

```php
// config/cloud-tasks.php

// If the application only dispatches jobs
'disable_task_handler' => env('STACKKIT_CLOUD_TASKS_DISABLE_TASK_HANDLER', false),
```

```dotenv
QUEUE_CONNECTION=cloudtasks
```

Now that the package is installed, the final step is to set the correct environment variables.

Please check the table below on what the values mean and what their value should be.

| Environment variable                              | Description                                                                                                                                              | Example                                          
---------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------
| `STACKKIT_CLOUD_TASKS_PROJECT`                    | The project your queue belongs to.                                                                                                                       | `my-project`                                     
| `STACKKIT_CLOUD_TASKS_LOCATION`                   | The region where the project is hosted.                                                                                                                  | `europe-west6`                                   
| `STACKKIT_CLOUD_TASKS_QUEUE`                      | The default queue a job will be added to.                                                                                                                | `emails`                                         
| **App Engine**                                    
| `STACKKIT_APP_ENGINE_TASK` (optional)             | Set to true to use App Engine task (else a Http task will be used). Defaults to false.                                                                   | `true`                                           
| `STACKKIT_APP_ENGINE_SERVICE` (optional)          | The App Engine service to handle the task (only if using App Engine task).                                                                               | `api`                                            
| **Non- App Engine apps**                          
| `STACKKIT_CLOUD_TASKS_SERVICE_EMAIL`   (optional) | The email address of the service account. Important, it should have the correct roles. See the section below which roles.                                | `my-service-account@appspot.gserviceaccount.com` 
| `STACKKIT_CLOUD_TASKS_HANDLER` (optional)         | The URL that Cloud Tasks will call to process a job. This should be the URL to your Laravel app. By default we will use the URL that dispatched the job. | `https://<your website>.com`                     

</details>

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

<details>
<summary>
How it works & Differences
</summary>
<br>
Using Cloud Tasks as a Laravel queue driver is fundamentally different than other Laravel queue drivers, like Redis.

Typically a Laravel queue has a worker that listens to incoming jobs using the `queue:work` / `queue:listen` command.
With Cloud Tasks, this is not the case. Instead, Cloud Tasks will schedule the job for you and make an HTTP request to
your application with the job payload. There is no need to run a `queue:work/listen` command.

#### Good to know

- Backoff, retries, max tries, retryUntil are all handled by Laravel. All these options are ignored in the Cloud Tasks
  configuration.

</details>
<details>
<summary>Dashboard (beta)</summary>
<br>
The package comes with a beautiful dashboard that can be used to monitor all queued jobs.


<img src="/assets/dashboard.png" width="100%">

---

_Experimental_

The dashboard works by storing all outgoing tasks in a database table. When Cloud Tasks calls the application and this
package handles the task, we will automatically update the tasks' status, attempts
and possible errors.

There is probably a (small) performance penalty because each task dispatch and handling does extra database read and
writes.
Also, the dashboard has not been tested with high throughput queues.

---


To make use of it, enable it through the `.env` file:

```dotenv
STACKKIT_CLOUD_TASKS_DASHBOARD_ENABLED=true
STACKKIT_CLOUD_TASKS_DASHBOARD_PASSWORD=MySecretLoginPasswordPleaseChangeThis
```

Then publish its assets and migrations:

```console
php artisan vendor:publish --tag=cloud-tasks
php artisan migrate
```

The dashboard is accessible at the URI: /cloud-tasks

</details>
<details>
<summary>Authentication</summary>
<br>

Set the `GOOGLE_APPLICATION_CREDENTIALS` environment variable with a path to the credentials file.

More info: https://cloud.google.com/docs/authentication/production

If you're not using your master service account (which has all abilities), you must add the following roles to make it
works:

1. App Engine Viewer
2. Cloud Tasks Enqueuer
3. Cloud Tasks Viewer
4. Cloud Tasks Task Deleter
5. Service Account User

</details>
<details>
<summary>Security</summary>
<br>
The job handler requires each request to have an OpenID token. In the installation step we set the service account email, and with that service account, Cloud Tasks will generate an OpenID token and send it along with the job payload to the handler.

This package verifies that the token is digitally signed by Google. Only Google Tasks will be able to call your handler.

More information about OpenID Connect:

https://developers.google.com/identity/protocols/oauth2/openid-connect
</details>
<details>
<summary>Upgrading</summary>
<br>
Read [UPGRADING.MD](UPGRADING.md) on how to update versions.
</details>
