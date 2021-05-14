<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

class TaskHandler
{
    private $request;
    private $publicKey;

    public function __construct(CloudTasksClient $client, Request $request, OpenIdVerificator $publicKey)
    {
        $this->client = $client;
        $this->request = $request;
        $this->publicKey = $publicKey;
    }

    /**
     * @param $task
     * @throws CloudTasksException
     */
    public function handle($task = null)
    {
        $this->authorizeRequest();

        $task = $task ?: $this->captureTask();

        $this->listenForEvents();

        $this->handleTask($task);
    }

    /**
     * @throws CloudTasksException
     */
    public function authorizeRequest()
    {
        if (!$this->request->hasHeader('Authorization')) {
            throw new CloudTasksException('Missing [Authorization] header');
        }

        $openIdToken = $this->request->bearerToken();
        $kid = $this->publicKey->getKidFromOpenIdToken($openIdToken);

        $decodedToken = $this->publicKey->decodeOpenIdToken($openIdToken, $kid);

        $this->validateToken($decodedToken);
    }

    /**
     * https://developers.google.com/identity/protocols/oauth2/openid-connect#validatinganidtoken
     *
     * @param $openIdToken
     * @throws CloudTasksException
     */
    protected function validateToken($openIdToken)
    {
        if (!in_array($openIdToken->iss, ['https://accounts.google.com', 'accounts.google.com'])) {
            throw new CloudTasksException('The given OpenID token is not valid');
        }

        if ($openIdToken->aud != Config::handler()) {
            throw new CloudTasksException('The given OpenID token is not valid');
        }

        if ($openIdToken->exp < time()) {
            throw new CloudTasksException('The given OpenID token has expired');
        }
    }

    /**
     * @throws CloudTasksException
     */
    private function captureTask()
    {
        $input = (string) (request()->getContent());

        if (!$input) {
            throw new CloudTasksException('Could not read incoming task');
        }

        $task = json_decode($input, true);

        if (is_null($task)) {
            throw new CloudTasksException('Could not decode incoming task');
        }

        return $task;
    }

    private function listenForEvents()
    {
        app('events')->listen(JobFailed::class, function ($event) {
            app('queue.failer')->log(
                'cloudtasks', $event->job->getQueue(),
                $event->job->getRawBody(), $event->exception
            );
        });
    }

    /**
     * @param $task
     * @throws CloudTasksException
     */
    private function handleTask($task)
    {
        $job = new CloudTasksJob($task);

        $job->setAttempts(request()->header('X-CloudTasks-TaskRetryCount') + 1);
        $job->setQueue(request()->header('X-Cloudtasks-Queuename'));
        $job->setMaxTries($this->getQueueMaxTries($job));

        $worker = $this->getQueueWorker();

        $worker->process('cloudtasks', $job, new WorkerOptions());
    }

    private function getQueueMaxTries(CloudTasksJob $job)
    {
        $queueName = $this->client->queueName(
            Config::project(),
            Config::location(),
            $job->getQueue()
        );

        return $this->client
            ->getQueue($queueName)
            ->getRetryConfig()
            ->getMaxAttempts();
    }

    /**
     * @return Worker
     */
    private function getQueueWorker()
    {
        return app('queue.worker');
    }
}
