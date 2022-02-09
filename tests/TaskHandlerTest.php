<?php

namespace Tests;

use Carbon\Carbon;
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
use Tests\Support\FailingJob;
use Tests\Support\SimpleJob;
use Tests\Support\TestMailable;

class TaskHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearLaravelStorageFile();
        $this->clearTables();
    }

    /** @test */
    public function it_runs_the_incoming_job()
    {
        // Act
        dispatch(new SimpleJob());

        // Assert
        $this->assertLogContains('SimpleJob:success');
    }

    /** @test */
    public function after_max_attempts_it_will_log_to_failed_table()
    {
        // Act
        $this->assertDatabaseCount('failed_jobs', 0);
        dispatch(new FailingJob());
        $this->sleep(500);

        // Assert
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', [
            'connection' => 'cloudtasks',
            'queue' => 'barbequeue',
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

    /**
     * @test
     * @dataProvider whenIsJobFailingProvider
     */
    public function job_max_attempts_is_ignored_if_has_retry_until($example)
    {
        // Arrange
        $this->request->headers->add(['X-CloudTasks-TaskRetryCount' => $example['retryCount']]);

        if (array_key_exists('travelSeconds', $example)) {
            Carbon::setTestNow(Carbon::now()->addSeconds($example['travelSeconds']));
        }

        $this->cloudTasksClient->shouldReceive('getQueue')
            ->byDefault()
            ->andReturn(new class() {
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

        $this->cloudTasksClient
            ->shouldReceive('getTask')
            ->byDefault()
            ->andReturn(new class {
                public function getFirstAttempt() {
                    return (new Attempt())
                        ->setDispatchTime(new Timestamp([
                            'seconds' => time(),
                        ]));
                }
            });

        rescue(function () {
            $this->handler->handle($this->failingJob());
        });

        if ($example['shouldHaveFailed']) {
            $this->cloudTasksClient->shouldHaveReceived('deleteTask');
        } else {
            $this->cloudTasksClient->shouldNotHaveReceived('deleteTask');
        }
    }

    public function whenIsJobFailingProvider()
    {
        $this->createApplication();

        // 8.x behavior: if retryUntil, only check that.
        // 6.x behavior: if retryUntil, check that, otherwise check maxAttempts

        // max retry count is 3
        // max retryUntil is 30 seconds

        if (version_compare(app()->version(), '8.0.0', '>=')) {
            return [
                [
                    [
                        'retryCount' => 1,
                        'shouldHaveFailed' => false,
                    ],
                ],
                [
                    [
                        'retryCount' => 2,
                        'shouldHaveFailed' => false,
                    ],
                ],
                [
                    [
                        'retryCount' => 1,
                        'travelSeconds' => 29,
                        'shouldHaveFailed' => false,
                    ],
                ],
                [
                    [
                        'retryCount' => 1,
                        'travelSeconds' => 31,
                        'shouldHaveFailed' => true,
                    ],
                ],
                [
                    [
                        'retryCount' => 1,
                        'travelSeconds' => 32,
                        'shouldHaveFailed' => true,
                    ],
                ],
                [
                    [
                        'retryCount' => 1,
                        'travelSeconds' => 31,
                        'shouldHaveFailed' => true,
                    ],
                ],
            ];
        }

        return [
            [
                [
                    'retryCount' => 1,
                    'shouldHaveFailed' => false,
                ],
            ],
            [
                [
                    'retryCount' => 2,
                    'shouldHaveFailed' => true,
                ],
            ],
            [
                [
                    'retryCount' => 1,
                    'travelSeconds' => 29,
                    'shouldHaveFailed' => false,
                ],
            ],
            [
                [
                    'retryCount' => 1,
                    'travelSeconds' => 31,
                    'shouldHaveFailed' => true,
                ],
            ],
            [
                [
                    'retryCount' => 1,
                    'travelSeconds' => 32,
                    'shouldHaveFailed' => true,
                ],
            ],
            [
                [
                    'retryCount' => 1,
                    'travelSeconds' => 32,
                    'shouldHaveFailed' => true,
                ],
            ],
        ];
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
