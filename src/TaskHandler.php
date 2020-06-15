<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Google\Cloud\Tasks\V2\CloudTasksClient;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Arr;
use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;
use Throwable;
use Firebase\JWT\JWT;

class TaskHandler
{
    private $client;
    private $request;
    private $guzzle;
    private $jwt;

    public function __construct(CloudTasksClient $client, Request $request, Client $guzzle, JWT $jwt)
    {
        $this->client = $client;
        $this->request = $request;
        $this->guzzle = $guzzle;
        $this->jwt = $jwt;
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

        // @todo - kill this check with a Mock
        if (app()->environment('testing')) {
            return;
        }

        $openIdToken = $this->request->bearerToken();
        $pubKey = $this->getGooglePublicKey();

        $decodedToken = $this->jwt->decode($openIdToken, $pubKey, ['RS256']);

        $this->validateToken($decodedToken);
    }

    private function getGooglePublicKey()
    {
        $jwksUri = $this->getJwksUri();

        $keys = $this->getCertificateKeys($jwksUri);

        $firstKey = $keys[1];

        $modulus = $firstKey['n'];
        $exponent = $firstKey['e'];

        $rsa = new RSA();

        $modulus = new BigInteger(JWT::urlsafeB64Decode($modulus), 256);
        $exponent = new BigInteger(JWT::urlsafeB64Decode($exponent), 256);

        $rsa->loadKey([
            'n' => $modulus,
            'e' => $exponent
        ]);
        $rsa->setPublicKey();

        return $rsa->getPublicKey();
    }

    private function getJwksUri()
    {
        $discoveryEndpoint = 'https://accounts.google.com/.well-known/openid-configuration';

        $configurationJson = $this->guzzle->get($discoveryEndpoint);

        $configurations = json_decode($configurationJson->getBody(), true);

        return Arr::get($configurations, 'jwks_uri');
    }

    private function getCertificateKeys($jwksUri)
    {
        $json = $this->guzzle->get($jwksUri);

        $certificates = json_decode($json->getBody(), true);

        return Arr::get($certificates, 'keys');
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

        $task = json_decode($input, true, JSON_THROW_ON_ERROR);

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
