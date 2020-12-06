<?php

namespace Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksException;
use Stackkit\LaravelGoogleCloudTasksQueue\OpenIdVerificator;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;
use Tests\Support\TestMailable;

class TaskHandlerTest extends TestCase
{
    /**
     * @var TaskHandler
     */
    private $handler;

    private $jwt;

    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = request();

        // We don't have a valid token to test with, so for now act as if its always valid
        $this->app->instance(JWT::class, ($this->jwt = Mockery::mock(new JWT())->byDefault()->makePartial()));
        $this->jwt->shouldReceive('decode')->andReturn((object) [
            'iss' => 'accounts.google.com',
            'aud' => 'https://localhost/my-handler',
            'exp' => time() + 10
        ])->byDefault();

        // Ensure we don't fetch the Google public key each test...
        $googlePublicKey = Mockery::mock(app(OpenIdVerificator::class));
        $googlePublicKey->shouldReceive('getPublicKey')->andReturnNull();
        $googlePublicKey->shouldReceive('getKidFromOpenIdToken')->andReturnNull();

        $this->handler = new TaskHandler(
            new CloudTasksClient(),
            request(),
            $this->jwt,
            $googlePublicKey
        );

        $this->request->headers->add(['Authorization' => 'Bearer 123']);
    }

    /** @test */
    public function it_needs_an_authorization_header()
    {
        $this->request->headers->remove('Authorization');

        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Missing [Authorization] header');

        $this->handler->handle();
    }

    /** @test */
    public function the_authorization_header_must_contain_a_valid_gcloud_token()
    {
        request()->headers->add([
            'Authorization' => 'Bearer 123',
        ]);

        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('Could not decode incoming task');

        $this->handler->handle();

        // @todo - test with a valid token, not sure how to do that right now
    }

    /** @test */
    public function it_will_validate_the_token_iss()
    {
        $this->jwt->shouldReceive('decode')->andReturn((object) [
            'iss' => 'test',
        ]);
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('The given OpenID token is not valid');
        $this->handler->handle($this->simpleJob());
    }

    /** @test */
    public function it_will_validate_the_token_handler()
    {
        $this->jwt->shouldReceive('decode')->andReturn((object) [
            'iss' => 'accounts.google.com',
            'aud' => '__incorrect_aud__'
        ]);
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('The given OpenID token is not valid');
        $this->handler->handle($this->simpleJob());
    }

    /** @test */
    public function it_will_validate_the_token_expiration()
    {
        $this->jwt->shouldReceive('decode')->andReturn((object) [
            'iss' => 'accounts.google.com',
            'aud' => 'https://localhost/my-handler',
            'exp' => time() - 1
        ]);
        $this->expectException(CloudTasksException::class);
        $this->expectExceptionMessage('The given OpenID token has expired');
        $this->handler->handle($this->simpleJob());
    }

    /** @test */
    public function in_case_of_signature_verification_failure_it_will_retry()
    {
        Event::fake();

        $this->jwt->shouldReceive('decode')->andThrow(SignatureInvalidException::class);

        $this->expectException(SignatureInvalidException::class);

        $this->handler->handle($this->simpleJob());

        Event::assertDispatched(CacheHit::class);
        Event::assertDispatched(KeyWritten::class);
    }

    /** @test */
    public function it_runs_the_incoming_job()
    {
        Mail::fake();

        request()->headers->add(['Authorization' => 'Bearer 123']);

        $this->handler->handle($this->simpleJob());

        Mail::assertSent(TestMailable::class);
    }

    private function simpleJob()
    {
        return json_decode(file_get_contents(__DIR__ . '/Support/test-job-payload.json'), true);
    }
}
