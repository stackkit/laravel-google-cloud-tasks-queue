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
