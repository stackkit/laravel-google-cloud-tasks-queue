<?php

namespace Tests;

use Illuminate\Support\Facades\Cache;
use Mockery;
use phpseclib\Crypt\RSA;
use Stackkit\LaravelGoogleCloudTasksQueue\GooglePublicKey;

class GooglePublicKeyTest extends TestCase
{
    /**
     * @var GooglePublicKey
     */
    private $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicKey = app(GooglePublicKey::class);
    }

    /** @test */
    public function it_fetches_the_gcloud_public_key()
    {
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $this->publicKey->get());
    }

    /** @test */
    public function it_caches_the_gcloud_public_key()
    {
        $this->assertFalse($this->publicKey->isCached());

        $this->publicKey->get();

        $this->assertTrue($this->publicKey->isCached());
    }

    /** @test */
    public function it_will_return_the_cached_gcloud_public_key()
    {
        $this->app->instance(RSA::class, ($rsa = Mockery::mock(new RSA())->byDefault()));

        $this->publicKey->get();

        $this->publicKey->get();

        $rsa->shouldHaveReceived('loadKey')->once();
    }
}
