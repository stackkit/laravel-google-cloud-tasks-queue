<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Queue\Worker as LaravelWorker;
use Illuminate\Queue\WorkerOptions;

/**
 * Custom worker class to handle specific requirements for Google Cloud Tasks.
 *
 * This class modifies the behavior of the Laravel queue worker to better
 * integrate with Google Cloud Tasks, particularly focusing on job timeout
 * handling and graceful shutdowns to avoid interrupting the HTTP lifecycle.
 *
 * Firstly, the 'supportsAsyncSignals', 'listenForSignals', and 'registerTimeoutHandler' methods
 * are protected and called within the queue while(true) loop. We want (and need!) to have that
 * too in order to support job timeouts. So, to make it work, we create a public method that
 * can call the private signal methods.
 *
 * Secondly, we need to override the 'kill' method because it tends to kill the server process (artisan serve, octane),
 * as well as abort the HTTP request from Cloud Tasks. This is not the desired behavior.
 * Instead, it should just fire the WorkerStopped event and return a normal status code.
 */
class Worker extends LaravelWorker
{
    public function process($connectionName, $job, WorkerOptions $options): void
    {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();

            $this->registerTimeoutHandler($job, $options);
        }

        parent::process($connectionName, $job, $options);

        if ($this->supportsAsyncSignals()) {
            $this->resetTimeoutHandler();
        }
    }

    public function kill($status = 0, $options = null): void
    {
        if ($this->supportsAsyncSignals()) {
            $this->resetTimeoutHandler();
        }

        parent::stop($status, $options);

        // When running tests, we cannot run exit because it will kill the PHPunit process.
        // So, to still test that the application has exited, we will simply rely on the
        // WorkerStopped event that is fired when the worker is stopped.
        if (! app()->runningUnitTests()) {
            if (extension_loaded('posix') && extension_loaded('pcntl')) {
                posix_kill(getmypid(), SIGKILL);
            }

            exit($status);
        }

    }
}
