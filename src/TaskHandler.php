<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Http\Request;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Firebase\JWT\JWT;

class TaskHandler
{
    private $request;
    private $guzzle;
    private $jwt;
    private $publicKey;

    public function __construct(CloudTasksClient $client, Request $request, JWT $jwt, OpenIdVerificator $publicKey)
    {
        $this->client = $client;
        $this->request = $request;
        $this->jwt = $jwt;
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
        $publicKey = $this->publicKey->getPublicKey($kid);

        $decodedToken = $this->jwt->decode($openIdToken, $publicKey, ['RS256']);

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
        $input = file_get_contents('php://input');

        if ($input === false) {
            throw new CloudTasksException('Could not read incoming task');
        }

        $task = json_decode($input, true);

        if (is_null($task)) {
            throw new CloudTasksException('Could not decode incoming task');
        }

        return $task;
    }

    /**
     * @param $task
     * @throws CloudTasksException
     */
    private function handleTask($task)
    {
        $job = new CloudTasksJob($task, request()->header('X-CloudTasks-TaskRetryCount'));

        $worker = $this->getQueueWorker();

        $worker->process('cloudtasks', $job, new WorkerOptions());
    }

    /**
     * @return Worker
     */
    private function getQueueWorker()
    {
        return app('queue.worker');
    }
}
