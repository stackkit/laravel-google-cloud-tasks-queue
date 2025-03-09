<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use function Safe\set_time_limit;

use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Worker as LaravelWorker;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\ErrorHandler\Error\FatalError;

/**
 * Custom worker class to handle specific requirements for Google Cloud Tasks.
 *
 * This class modifies the behavior of the Laravel queue worker to better
 * integrate with Google Cloud Tasks, particularly focusing on job timeout
 * handling and graceful shutdowns to avoid interrupting the HTTP lifecycle.
 *
 * Firstly, normally job timeouts are handled using the pcntl extension. Since we
 * are running in an HTTP environment, we can't use those functions. An alternative
 * method is using set_time_limit and when PHP throws the fatal 'Maximum execution time exceeded' error,
 * we will handle the job error like how Laravel would if the pcntl alarm had gone off.
 */
class Worker extends LaravelWorker
{
    public function process($connectionName, $job, WorkerOptions $options): void
    {
        assert($job instanceof CloudTasksJob);

        set_time_limit(max($this->timeoutForJob($job, $options), 0));

        app(ExceptionHandler::class)->reportable(
            fn (FatalError $error) => $this->onFatalError($error, $job, $options)
        );

        parent::process($connectionName, $job, $options);
    }

    private function onFatalError(FatalError $error, CloudTasksJob $job, WorkerOptions $options): bool
    {
        if (fnmatch('Maximum execution time * exceeded', $error->getMessage())) {
            $this->onJobTimedOut($job, $options);

            return false;
        }

        return true;
    }

    private function onJobTimedOut(CloudTasksJob $job, WorkerOptions $options): void
    {
        $this->markJobAsFailedIfWillExceedMaxAttempts(
            $job->getConnectionName(), $job, (int) $options->maxTries, $e = $this->timeoutExceededException($job)
        );

        $this->markJobAsFailedIfWillExceedMaxExceptions(
            $job->getConnectionName(), $job, $e
        );

        $this->markJobAsFailedIfItShouldFailOnTimeout(
            $job->getConnectionName(), $job, $e
        );

        $this->events->dispatch(new JobTimedOut(
            $job->getConnectionName(), $job
        ));

        if (! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
            $job->release($this->calculateBackoff($job, $options));
        }
    }
}
