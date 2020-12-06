<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;

class OpenIdVerificator
{
    private const V3_CERTS = 'GOOGLE_V3_CERTS';
    private const URL_OPENID_CONFIG = 'https://accounts.google.com/.well-known/openid-configuration';
    private const URL_TOKEN_INFO = 'https://www.googleapis.com/oauth2/v3/tokeninfo';

    private $guzzle;
    private $rsa;
    private $jwt;
    private $maxAge = [];

    public function __construct(Client $guzzle, RSA $rsa, JWT $jwt)
    {
        $this->guzzle = $guzzle;
        $this->rsa = $rsa;
        $this->jwt = $jwt;
    }

    public function decodeOpenIdToken($openIdToken, $kid, $cache = true)
    {
        if (!$cache) {
            $this->forgetFromCache();
        }

        $publicKey = $this->getPublicKey($kid);

        try {
            return $this->jwt->decode($openIdToken, $publicKey, ['RS256']);
        } catch (SignatureInvalidException $e) {
            if (!$cache) {
                throw $e;
            }

            return $this->decodeOpenIdToken($openIdToken, $kid, false);
        }
    }

    public function getPublicKey($kid = null)
    {
        if (Cache::has(self::V3_CERTS)) {
            $v3Certs = Cache::get(self::V3_CERTS);
        } else {
            $v3Certs = $this->getv3Certs();
            Cache::put(self::V3_CERTS, $v3Certs, $this->maxAge[self::URL_OPENID_CONFIG]);
        }

        $cert = $kid ? collect($v3Certs)->firstWhere('kid', '=', $kid) : $v3Certs[0];

        return $this->extractPublicKeyFromCertificate($cert);
    }

    private function getv3Certs()
    {
        $jwksUri =  $this->callApiAndReturnValue(self::URL_OPENID_CONFIG, 'jwks_uri');

        return $this->callApiAndReturnValue($jwksUri, 'keys');
    }

    private function extractPublicKeyFromCertificate($certificate)
    {
        $modulus = new BigInteger(JWT::urlsafeB64Decode($certificate['n']), 256);
        $exponent = new BigInteger(JWT::urlsafeB64Decode($certificate['e']), 256);

        $this->rsa->loadKey(compact('modulus', 'exponent'));

        return $this->rsa->getPublicKey();
    }

    public function getKidFromOpenIdToken($openIdToken)
    {
        return $this->callApiAndReturnValue(self::URL_TOKEN_INFO . '?id_token=' . $openIdToken, 'kid');
    }

    private function callApiAndReturnValue($url, $value)
    {
        $response = $this->guzzle->get($url);

        $data = json_decode($response->getBody(), true);

        $maxAge = 0;
        foreach ($response->getHeader('Cache-Control') as $line) {
            preg_match('/max-age=(\d+)/', $line, $matches);
            $maxAge = isset($matches[1]) ? (int) $matches[1] : 0;
        }

        $this->maxAge[$url] = $maxAge;

        return Arr::get($data, $value);
    }

    public function isCached()
    {
        return Cache::has(self::V3_CERTS);
    }

    public function forgetFromCache()
    {
        Cache::forget(self::V3_CERTS);
    }
}
