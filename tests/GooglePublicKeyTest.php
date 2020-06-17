<?php

namespace Tests;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Mockery;
use phpseclib\Crypt\RSA;
use Stackkit\LaravelGoogleCloudTasksQueue\OpenIdVerificator;

class GooglePublicKeyTest extends TestCase
{
    /**
     * @var OpenIdVerificator
     */
    private $publicKey;

    /**
     * @var Client
     */
    private $guzzle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guzzle = Mockery::mock(new Client());

        $this->publicKey = new OpenIdVerificator($this->guzzle, new RSA());
    }

    /** @test */
    public function it_fetches_the_gcloud_public_key()
    {
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $this->publicKey->getPublicKey());
    }

    /** @test */
    public function it_caches_the_gcloud_public_key()
    {
        $this->assertFalse($this->publicKey->isCached());

        $this->publicKey->getPublicKey();

        $this->assertTrue($this->publicKey->isCached());
    }

    /** @test */
    public function it_will_return_the_cached_gcloud_public_key()
    {
        $this->publicKey->getPublicKey();

        $this->publicKey->getPublicKey();

        $this->guzzle->shouldHaveReceived('get')->twice();
    }
}
