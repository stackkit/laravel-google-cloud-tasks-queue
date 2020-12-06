<?php

namespace Tests;

use Carbon\Carbon;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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

        $this->publicKey = new OpenIdVerificator($this->guzzle, new RSA(), new JWT());
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
        Event::fake();

        $this->publicKey->getPublicKey();

        Event::assertDispatched(CacheMissed::class);
        Event::assertDispatched(KeyWritten::class);

        $this->publicKey->getPublicKey();

        Event::assertDispatched(CacheHit::class);

        $this->guzzle->shouldHaveReceived('get')->twice();
    }

    /** @test */
    public function public_key_is_cached_according_to_cache_control_headers()
    {
        Event::fake();

        $this->publicKey->getPublicKey();

        $this->publicKey->getPublicKey();

        Carbon::setTestNow(Carbon::now()->addSeconds(3600));
        $this->publicKey->getPublicKey();

        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        $this->publicKey->getPublicKey();

        Event::assertDispatched(CacheMissed::class, 2);
        Event::assertDispatched(KeyWritten::class, 2);

    }
}
