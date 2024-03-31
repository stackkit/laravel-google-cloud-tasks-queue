# From 3.x to 4.x

## Renamed environment names (Impact: high)

The following environment variables have been shortened:
- `STACKKIT_CLOUD_TASKS_PROJECT` → `CLOUD_TASKS_PROJECT`
- `STACKKIT_CLOUD_TASKS_LOCATION` → `CLOUD_TASKS_LOCATION`
- `STACKKIT_CLOUD_TASKS_QUEUE` → `CLOUD_TASKS_QUEUE`
- `STACKKIT_CLOUD_TASKS_HANDLER` → `CLOUD_TASKS_HANDLER`
- `STACKKIT_CLOUD_TASKS_SERVICE_EMAIL` → `CLOUD_TASKS_SERVICE_EMAIL`

The following environment variables have been renamed to be more consistent:

- `STACKKIT_APP_ENGINE_TASK` → `CLOUD_TASKS_APP_ENGINE_TASK`
- `STACKKIT_APP_ENGINE_SERVICE` → `CLOUD_TASKS_APP_ENGINE_SERVICE`

The following environment variable has been removed:
- `STACKKIT_CLOUD_TASKS_SIGNED_AUDIENCE`

## Removed dashboard (Impact: high)

The dashboard has been removed to keep the package minimal. A separate composer package might be created with an updated version of the dashboard.

## New configuration file (Impact: medium)

The configuration file has been updated to reflect the removed dashboard and to add new configurable options.

Please publish the new configuration file:

```shell
php artisan vendor:publish --tag=cloud-tasks --force
```

## Dispatch deadline (Impact: medium)

The `dispatch_deadline` has been removed from the task configuration. You may now use Laravel's timeout configuration to control the maximum execution time of a task.


# From 2.x to 3.x

PHP 7.2 and 7.3, and Laravel 5.x are no longer supported.

## Update handler URL (Impact: high)

The handler URL environment has been simplified. Please change it like this:

```dotenv
# Before
STACKKIT_CLOUD_TASKS_HANDLER=https://my-app/handle-task
# After
STACKKIT_CLOUD_TASKS_HANDLER=https://my-app
```

It's also allowed to remove this variable entirely in 3.x: The package will automatically use the application URL if the `STACKKIT_CLOUD_TASKS_HANDLER`
environment is not present. If you omit it, please ensure the [trusted proxy](https://laravel.com/docs/9.x/requests#configuring-trusted-proxies) have been configured
in your application. Otherwise, you might run into weird issues. :-)
