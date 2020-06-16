<?php

namespace Tests;

use Firebase\JWT\JWT;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use phpseclib\Crypt\RSA;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksException;
use Stackkit\LaravelGoogleCloudTasksQueue\GooglePublicKey;
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
        $googlePublicKey = Mockery::mock(app(GooglePublicKey::class));
        $googlePublicKey->shouldReceive('get')->andReturnNull();

        $this->handler = new TaskHandler(
            new CloudTasksClient([
                'credentials' => __DIR__ . '/Support/gcloud-key-valid.json'
            ]),
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
