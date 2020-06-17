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

    public function get($kid = null)
    {
        $v3Certs = Cache::rememberForever(
            self::CACHE_KEY,
            function () {
                return $this->getv3Certs();
            }
        );

        $cert = $kid ? collect($v3Certs)->firstWhere('kid', '=', $kid) : $v3Certs[0];

        return $this->extractPublicKeyFromCertificate($cert);
    }

    private function getv3Certs()
    {
        $jwksUri = $this->getJwksUri();

        return $this->getCertificateKeys($jwksUri);
    }

    private function extractPublicKeyFromCertificate($certificate)
    {
        $modulus = $certificate['n'];
        $exponent = $certificate['e'];

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

    public function getKid($openIdToken)
    {
        $response = $this->guzzle->get('https://www.googleapis.com/oauth2/v3/tokeninfo?id_token=' . $openIdToken);

        $tokenInfo = json_decode($response->getBody(), true);

        return Arr::get($tokenInfo, 'kid');
    }

    public function isCached()
    {
        return Cache::has(self::CACHE_KEY);
    }
}
