<?php

namespace Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Google\Cloud\Tasks\V2\Attempt;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\DB;
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

    private $cloudTasksClient;

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

        $cloudTasksClient = Mockery::mock(new CloudTasksClient())->byDefault();
        $this->cloudTasksClient = $cloudTasksClient;

        // Ensure we don't fetch the Queue name and attempts each test...
        $cloudTasksClient->shouldReceive('queueName')->andReturn('my-queue');
        $cloudTasksClient->shouldReceive('getQueue')
            ->byDefault()
            ->andReturn(new class {
                public function getRetryConfig() {
                    return new class {
                        public function getMaxAttempts() {
                            return 3;
                        }

                        public function hasMaxRetryDuration() {
                            return true;
                        }

                        public function getMaxRetryDuration() {
                            return new class {
                                public function getSeconds() {
                                    return 30;
                                }
                            };
                        }
                    };
                }
            });
        $cloudTasksClient->shouldReceive('taskName')->andReturn('FakeTaskName');
        $cloudTasksClient->shouldReceive('getTask')->byDefault()->andReturn(new class {
            public function getFirstAttempt() {
                return null;
            }
        });

        $cloudTasksClient->shouldReceive('deleteTask')->andReturnNull();

        $this->handler = new TaskHandler(
            $cloudTasksClient,
            request(),
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

        $this->handler->handle($this->simpleJob());
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

    /** @test */
    public function after_max_attempts_it_will_log_to_failed_table()
    {
        $this->request->headers->add(['X-Cloudtasks-Queuename' => 'my-queue']);

        $this->request->headers->add(['X-CloudTasks-TaskRetryCount' => 1]);
        try {
            $this->handler->handle($this->failingJob());
        } catch (\Throwable $e) {
            //
        }

        $this->assertCount(0, DB::table('failed_jobs')->get());

        $this->request->headers->add(['X-CloudTasks-TaskRetryCount' => 2]);
        try {
            $this->handler->handle($this->failingJob());
        } catch (\Throwable $e) {
            //
        }

        $this->assertDatabaseHas('failed_jobs', [
            'connection' => 'my-cloudtasks-connection',
            'queue' => 'my-queue',
            'payload' => rtrim($this->failingJobPayload()),
        ]);
    }

    /** @test */
    public function after_max_attempts_it_will_delete_the_task()
    {
        $this->request->headers->add(['X-CloudTasks-TaskRetryCount' => 2]);

        rescue(function () {
            $this->handler->handle($this->failingJob());
        });

        $this->cloudTasksClient->shouldHaveReceived('deleteTask')->once();
    }

    /** @test */
    public function after_max_retry_until_it_will_delete_the_task()
    {
        $this->request->headers->add(['X-CloudTasks-TaskRetryCount' => 1]);

        $this->cloudTasksClient
            ->shouldReceive('getTask')
            ->byDefault()
            ->andReturn(new class {
                public function getFirstAttempt() {
                    return (new Attempt())
                        ->setDispatchTime(new Timestamp([
                            'seconds' => time() - 29,
                        ]));
                }
            });

        rescue(function () {
            $this->handler->handle($this->failingJob());
        });

        $this->cloudTasksClient->shouldNotHaveReceived('deleteTask');

        $this->cloudTasksClient->shouldReceive('getTask')
            ->andReturn(new class {
                public function getFirstAttempt() {
                    return (new Attempt())
                        ->setDispatchTime(new Timestamp([
                            'seconds' => time() - 30,
                        ]));
                }
            });

        rescue(function () {
            $this->handler->handle($this->failingJob());
        });

        $this->cloudTasksClient->shouldHaveReceived('deleteTask')->once();
    }

    /** @test */
    public function test_unlimited_max_attempts()
    {
        $this->cloudTasksClient->shouldReceive('getQueue')
            ->byDefault()
            ->andReturn(new class {
                public function getRetryConfig() {
                    return new class {
                        public function getMaxAttempts() {
                            return -1;
                        }

                        public function hasMaxRetryDuration() {
                            return false;
                        }
                    };
                }
            });

        for ($i = 0; $i < 50; $i++) {
            $this->request->headers->add(['X-CloudTasks-TaskRetryCount' => $i]);

            rescue(function () {
                $this->handler->handle($this->failingJob());
            });

            $this->cloudTasksClient->shouldNotHaveReceived('deleteTask');
        }

    }

    private function simpleJob()
    {
        return json_decode(file_get_contents(__DIR__ . '/Support/test-job-payload.json'), true);
    }

    private function failingJobPayload()
    {
        return file_get_contents(__DIR__ . '/Support/failing-job-payload.json');
    }

    private function failingJob()
    {
        return json_decode($this->failingJobPayload(), true);
    }
}
