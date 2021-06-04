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
        $task = $task ?: $this->captureTask();

        $command = unserialize($task['data']['command']);
        $connection = $command->connection ?? config('queue.default');

        $this->authorizeRequest($connection);

        $this->listenForEvents($connection);

        $this->handleTask($connection, $task);
    }

    /**
     * @throws CloudTasksException
     */
    public function authorizeRequest($connection)
    {
        if (!$this->request->hasHeader('Authorization')) {
            throw new CloudTasksException('Missing [Authorization] header');
        }

        $openIdToken = $this->request->bearerToken();
        $kid = $this->publicKey->getKidFromOpenIdToken($openIdToken);

        $decodedToken = $this->publicKey->decodeOpenIdToken($openIdToken, $kid);

        $this->validateToken($connection, $decodedToken);
    }

    /**
     * https://developers.google.com/identity/protocols/oauth2/openid-connect#validatinganidtoken
     *
     * @param $openIdToken
     * @throws CloudTasksException
     */
    protected function validateToken($connection, $openIdToken)
    {
        if (!in_array($openIdToken->iss, ['https://accounts.google.com', 'accounts.google.com'])) {
            throw new CloudTasksException('The given OpenID token is not valid');
        }

        if ($openIdToken->aud != Config::handler($connection)) {
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

    private function listenForEvents($connection)
    {
        app('events')->listen(JobFailed::class, function ($event) use ($connection) {
            app('queue.failer')->log(
                $connection, $event->job->getQueue(),
                $event->job->getRawBody(), $event->exception
            );
        });
    }

    /**
     * @param $task
     * @throws CloudTasksException
     */
    private function handleTask($connection, $task)
    {
        $job = new CloudTasksJob($task);

        $job->setAttempts(request()->header('X-CloudTasks-TaskRetryCount') + 1);
        $job->setQueue(request()->header('X-Cloudtasks-Queuename'));
        $job->setMaxTries($this->getQueueMaxTries($connection, $job));

        $worker = $this->getQueueWorker();

        $worker->process($connection, $job, new WorkerOptions());
    }

    private function getQueueMaxTries($connection, CloudTasksJob $job)
    {
        $queueName = $this->client->queueName(
            Config::project($connection),
            Config::location($connection),
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
