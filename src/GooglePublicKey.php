<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;

class GooglePublicKey
{
    private const CACHE_KEY = 'GooglePublicKey';

    private $guzzle;

    public function __construct(Client $guzzle)
    {
        $this->guzzle = $guzzle;
    }

    public function get()
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return $this->fetch();
        });
    }

    private function fetch()
    {
        $jwksUri = $this->getJwksUri();

        $keys = $this->getCertificateKeys($jwksUri);

        $firstKey = $keys[1];

        $modulus = $firstKey['n'];
        $exponent = $firstKey['e'];

        $rsa = app(RSA::class);

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

    public function isCached()
    {
        return Cache::has(self::CACHE_KEY);
    }
}
