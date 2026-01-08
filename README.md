# Cloud Tasks Queue Driver for Laravel

[![Run tests](https://github.com/stackkit/laravel-google-cloud-tasks-queue/actions/workflows/run-tests.yml/badge.svg)](https://github.com/stackkit/laravel-google-cloud-tasks-queue/actions/workflows/run-tests.yml)  
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/v/stable.svg" alt="Latest Stable Version"></a>  
<a href="https://packagist.org/packages/stackkit/laravel-google-cloud-tasks-queue"><img src="https://poser.pugx.org/stackkit/laravel-google-cloud-tasks-queue/downloads.svg" alt="Downloads"></a>

This package allows you to use Google Cloud Tasks as the queue driver in your Laravel application.

<sub>Companion packages: <a href="https://github.com/stackkit/laravel-google-cloud-scheduler">Cloud Scheduler</a>, <a href="https://github.com/marickvantuil/laravel-google-cloud-logging">Cloud Logging</a></sub>

![Image](https://github.com/user-attachments/assets/d9af0938-43b7-407b-8791-83419420a62b)



### Requirements

This package requires Laravel 11 or 12.

### Installation

Require the package via Composer:

```shell
composer require stackkit/laravel-google-cloud-tasks-queue
```

Add a new queue connection to `config/queue.php`:

```php
'cloudtasks' => [
  'driver' => 'cloudtasks',
  'project' => env('CLOUD_TASKS_PROJECT', ''),
  'location' => env('CLOUD_TASKS_LOCATION', ''),
  'queue' => env('CLOUD_TASKS_QUEUE', 'default'),

  // Required when using App Engine
  'app_engine'            => env('APP_ENGINE_TASK', false),
  'app_engine_service'    => env('APP_ENGINE_SERVICE', ''),

  // Required when not using App Engine
  'handler'               => env('CLOUD_TASKS_HANDLER', ''),
  'service_account_email' => env('CLOUD_TASKS_SERVICE_EMAIL', ''),

  'backoff' => 0,
  'after_commit' => false,
  // Enable this if you want to set a non-default Google Cloud Tasks dispatch timeout
  //'dispatch_deadline' => 1800, // in seconds
],
```

Set the appropriate environment variables:

```dotenv
QUEUE_CONNECTION=cloudtasks
```

If you're using Cloud Run:

```dotenv
CLOUD_TASKS_PROJECT=my-project
CLOUD_TASKS_LOCATION=europe-west6
CLOUD_TASKS_QUEUE=barbequeue
CLOUD_TASKS_SERVICE_EMAIL=my-service-account@appspot.gserviceaccount.com
# Optional (when using a separate task handler):
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

Refer to the table below for descriptions of each value:

| Environment Variable                  | Description                                                                                                                                              | Example                                           |
|--------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------|
| `CLOUD_TASKS_PROJECT`                | The project your queue belongs to.                                                                                                                       | `my-project`                                     |
| `CLOUD_TASKS_LOCATION`               | The region where the project is hosted.                                                                                                                  | `europe-west6`                                   |
| `CLOUD_TASKS_QUEUE`                  | The default queue to which a job will be added.                                                                                                          | `emails`                                         |
| **App Engine**                       |                                                                                                                                                          |                                                  |
| `APP_ENGINE_TASK` (optional)         | Set to true to use an App Engine task (otherwise an HTTP task will be used). Defaults to false.                                                         | `true`                                           |
| `APP_ENGINE_SERVICE` (optional)      | The App Engine service that will handle the task (only if using App Engine tasks).                                                                      | `api`                                            |
| **Non-App Engine Apps**              |                                                                                                                                                          |                                                  |
| `CLOUD_TASKS_SERVICE_EMAIL` (optional) | The service account's email address. It must have the required roles (see below).                                                                       | `my-service-account@appspot.gserviceaccount.com` |
| `CLOUD_TASKS_HANDLER` (optional)     | The URL that Cloud Tasks will call to process a job. Should point to your Laravel app. Defaults to the URL that dispatched the job.                     | `https://<your-website>.com`                     |

---

Optionally, you may publish the config file:

```console
php artisan vendor:publish --tag=cloud-tasks
```

If you're using separate services for dispatching and handling tasks, and your app should only dispatch jobs (not handle them), you may disable the task handler in `config/cloud-tasks.php`:

```php
'disable_task_handler' => env('CLOUD_TASKS_DISABLE_TASK_HANDLER', false),
```

### Cloud Run Jobs

If you want jobs to be processed by Cloud Run Jobs instead of HTTP endpoints, you can configure the queue to trigger Cloud Run Job executions.

#### Why Cloud Run Jobs?

Cloud Run Jobs are ideal for long-running batch processing that exceeds Cloud Tasks HTTP timeout limits. 

Cloud Run Jobs can run for up to 7 days.

**Tip**: Use seperate queue connections with different targets, for low latency jobs, use HTTP targets, for longer running batch jobs use Cloud Run Jobs.

#### Setup

1. **Create a Cloud Run Job** with your Laravel application container, configured to run:

```bash
php artisan cloud-tasks:work-job
```

The command reads job data from environment variables passed to the Job by Cloud Run.

2. **Configure your queue connection**:

```php
'cloudtasks' => [
    'driver' => 'cloudtasks',
    'project' => env('CLOUD_TASKS_PROJECT'),
    'location' => env('CLOUD_TASKS_LOCATION'),
    'queue' => env('CLOUD_TASKS_QUEUE', 'default'),
    
    // Cloud Run Job configuration
    'cloud_run_job' => env('CLOUD_TASKS_USE_CLOUD_RUN_JOB', false),
    'cloud_run_job_name' => env('CLOUD_RUN_JOB_NAME'),
    'cloud_run_job_region' => env('CLOUD_RUN_JOB_REGION'), // defaults to location
    'service_account_email' => env('CLOUD_TASKS_SERVICE_EMAIL'),
    
    // Optional: Store large payloads (>10KB) in filesystem
    'payload_disk' => env('CLOUD_TASKS_PAYLOAD_DISK'), // Laravel disk name
    'payload_prefix' => env('CLOUD_TASKS_PAYLOAD_PREFIX', 'cloud-tasks-payloads'),
    'payload_threshold' => env('CLOUD_TASKS_PAYLOAD_THRESHOLD', 10240), // bytes
],
```

> **Note**: The command reads `CLOUD_TASKS_PAYLOAD`, `CLOUD_TASKS_TASK_NAME`, and `CLOUD_TASKS_PAYLOAD_PATH` directly from environment variables at runtime using `getenv()`. These are set automatically by Cloud Tasks via container overrides.

3. **Set environment variables**:

```dotenv
CLOUD_TASKS_USE_CLOUD_RUN_JOB=true
CLOUD_RUN_JOB_NAME=my-queue-worker-job
CLOUD_RUN_JOB_REGION=europe-west1
```

#### Large Payload Storage

For jobs with payloads exceeding environment variable limits (32KB limit enforced by Cloud Run), configure a Laravel filesystem disk:

```dotenv
CLOUD_TASKS_PAYLOAD_DISK=gcs
CLOUD_TASKS_PAYLOAD_PREFIX=cloud-tasks-payloads
CLOUD_TASKS_PAYLOAD_THRESHOLD=30000
```

When the payload exceeds the threshold, it's stored in the disk and `CLOUD_TASKS_PAYLOAD_PATH` is used instead.

> **Note**: The payloads will not be cleared up automatically, you can define lifecycle rules for the GCS bucket to delete old payloads.

#### How It Works

When you dispatch a job with Cloud Run Job target enabled:

1. Package creates a Cloud Task with HTTP target pointing to Cloud Run Jobs API
2. Cloud Tasks calls `run.googleapis.com/v2/.../jobs/{job}:run`
3. Cloud Run Jobs starts a new execution with environment variables set via container overrides:
   - `CLOUD_TASKS_PAYLOAD` - Base64-encoded job payload
   - `CLOUD_TASKS_TASK_NAME` - The task name
4. The container runs `php artisan cloud-tasks:work-job` which reads the env vars and processes the job

All Laravel queue functionality is retained:
- Job retries and max attempts
- Failed job handling
- Job timeouts
- Encrypted jobs
- Queue events

#### Required IAM Permissions

Cloud Run Jobs requires specific IAM permissions. Set these variables first:

```bash
export PROJECT_ID="your-project-id"
export SA_EMAIL="your-service-account@your-project-id.iam.gserviceaccount.com"
export TASKS_AGENT="service-{PROJECT_NUMBER}@gcp-sa-cloudtasks.iam.gserviceaccount.com"
```

> **Note**: Find your Cloud Tasks service agent email in the IAM console under "Include Google-provided role grants".
> **Note**: Project ID and Project Number are different. Project ID is the name of your project, Project Number is the numeric ID of your project.

**Project-Level Permissions:**

```bash
# Allow enqueuing tasks (required by PHP app running as $SA_EMAIL)
gcloud projects add-iam-policy-binding $PROJECT_ID \
    --member="serviceAccount:$SA_EMAIL" \
    --role="roles/cloudtasks.enqueuer"

# Allow executing jobs with overrides (required for container overrides)
gcloud projects add-iam-policy-binding $PROJECT_ID \
    --member="serviceAccount:$SA_EMAIL" \
    --role="roles/run.jobsExecutorWithOverrides"

# Allow invoking Cloud Run Services (if also using Cloud Run Services as HTTP targets)
gcloud projects add-iam-policy-binding $PROJECT_ID \
    --member="serviceAccount:$SA_EMAIL" \
    --role="roles/run.invoker"
```

**Note**: To restrict access to specific Cloud Run instances, use IAM conditions to limit access to specific Cloud Run Jobs / services.

**Service Account Permissions:**

```bash
# Allow the SA to act as itself (required for task creation and execution)
gcloud iam service-accounts add-iam-policy-binding $SA_EMAIL \
    --member="serviceAccount:$SA_EMAIL" \
    --role="roles/iam.serviceAccountUser"

# Allow Cloud Tasks to act as the SA (required for OAuth token generation)
gcloud iam service-accounts add-iam-policy-binding $SA_EMAIL \
    --member="serviceAccount:$TASKS_AGENT" \
    --role="roles/iam.serviceAccountUser"
```

| Permission | Required By | Purpose |
|------------|-------------|---------|
| `cloudtasks.enqueuer` | PHP App | Add tasks to the queue |
| `cloudtasks.viewer` | Cloud Run Job | List queues/tasks (optional) |
| `run.jobsExecutorWithOverrides` | Cloud Task | Execute jobs with container overrides |
| `run.invoker` | Other Workloads | Invoke Cloud Run Services (if using HTTP targets) |
| `iam.serviceAccountUser` (on SA) | Both | Allow SA to create tasks as itself |
| `iam.serviceAccountUser` (Tasks Agent) | Google Infrastructure | Generate OAuth tokens for Cloud Run |

### How-To

#### Pass headers to a task

You can pass headers to a task using the `setTaskHeadersUsing` method on the `CloudTasksQueue` class:

```php
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

CloudTasksQueue::setTaskHeadersUsing(static fn() => [
  'X-My-Header' => 'My-Value',
]);
```

You can also access the payload being dispatched:

```php
CloudTasksQueue::setTaskHeadersUsing(static fn(array $payload) => [
  'X-My-Header' => $payload['displayName'],
]);
```

#### Configure the task handler URL

Set the handler URL for a task using the `configureHandlerUrlUsing` method:

```php
CloudTasksQueue::configureHandlerUrlUsing(static fn() => 'https://example.com/my-url');
```

Or access the job being dispatched:

```php
CloudTasksQueue::configureHandlerUrlUsing(static fn(MyJob $job) => 'https://example.com/my-url/' . $job->something());
```

#### Configure worker options

Customize worker options using the `configureWorkerOptionsUsing` method:

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

Edit the `client_options` key in `config/cloud-tasks.php`:

```php
'client_options' => [
    'credentials' => '/path/to/credentials.json',
]
```


#### Modify CloudTasksClient options

Edit the `client_options` key in `config/cloud-tasks.php`:

```php
'client_options' => [
    // Custom options here
]
```

### How it works & differences

Using Cloud Tasks as a Laravel queue driver is fundamentally different from other drivers like Redis.

With Redis or similar drivers, a worker listens for jobs via `queue:work` or `queue:listen`.  
With Cloud Tasks, jobs are scheduled and dispatched via HTTP requests to your app.  
There’s no need to run `queue:work` or `queue:listen`.

### Good to Know

Cloud Tasks has its own retry configuration options like:

- Maximum number of attempts  
- Retry duration  
- Min/max backoff  
- Max doublings

These are ignored by this package. Instead, you can configure retry behavior directly in Laravel.

### Authentication

If you're not using your master service account (which has all abilities), assign the following roles to your service account to make it working:

1. App Engine Viewer  
2. Cloud Tasks Enqueuer  
3. Cloud Tasks Viewer  
4. Cloud Tasks Task Deleter  
5. Service Account User

### Upgrading

See [UPGRADING.MD](UPGRADING.md) for instructions on updating versions.

### Troubleshooting

#### `HttpRequest.url` must start with `https://`

This can occur when your application runs behind a reverse proxy.  
To resolve it, add your app’s domain to Laravel’s [trusted proxies](https://laravel.com/docs/11.x/requests#trusting-all-proxies).  
You may need to use the wildcard `*`.

#### `Maximum call stack size (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?`

This seems to be a bug in PHP 8.3 and `googleapis/gax-php`.  
See [this issue](https://github.com/googleapis/gax-php/issues/584) for details.

A possible workaround is to disable the PHP 8.3 stack limit in `php.ini`:

```ini
zend.max_allowed_stack_size=-1
```

### Contributing

You can use the services defined in `docker-compose.yml` to run the package locally.

Inside the container:

1. Run `composer install`
2. Set up the environment: `cp .env.example .env`

Some tests use the Cloud Tasks API and require a project and credentials.  
Set the appropriate variables in your `.env`.
