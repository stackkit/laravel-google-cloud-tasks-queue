<?php

namespace Tests;

use Factories\StackkitCloudTaskFactory;
use Google\Cloud\Tasks\V2\RetryConfig;
use Illuminate\Support\Carbon;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\OpenIdVerificator;
use Stackkit\LaravelGoogleCloudTasksQueue\StackkitCloudTask;
use Tests\Support\FailingJob;
use Tests\Support\SimpleJob;

class CloudTasksMonitoringTest extends TestCase
{
    /**
     * @test
     */
    public function test_loading_dashboard_works()
    {
        // Arrange
        StackkitCloudTaskFactory::new()->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/dashboard');

        // Assert
        $response->assertStatus(200);
    }

    /**
     * @test
     */
    public function it_counts_the_number_of_tasks()
    {
        // Arrange
        Carbon::setTestNow(Carbon::parse('2022-01-01 15:15:00'));
        $lastMinute = now()->startOfMinute()->subMinute();
        $thisMinute = now()->startOfMinute();
        $thisHour = now()->startOfHour();
        $thisDay = now()->startOfDay();

        StackkitCloudTaskFactory::new()
            ->crossJoinSequence(
                [['status' => 'failed'], ['status' => 'queued']],
                [['created_at' => $thisMinute], ['created_at' => $thisHour], ['created_at' => $thisDay], ['created_at' => $lastMinute]]
            )
            ->count(8)
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/dashboard');

        // Assert
        $this->assertEquals(2, $response->json('recent.this_minute'));
        $this->assertEquals(6, $response->json('recent.this_hour'));
        $this->assertEquals(8, $response->json('recent.this_day'));

        $this->assertEquals(1, $response->json('failed.this_minute'));
        $this->assertEquals(3, $response->json('failed.this_hour'));
        $this->assertEquals(4, $response->json('failed.this_day'));
    }

    /**
     * @test
     */
    public function tasks_shows_newest_first()
    {
        // Arrange
        $tasks = StackkitCloudTaskFactory::new()
            ->count(2)
            ->sequence(
                ['created_at' => now()->subMinute()],
                ['created_at' => now()],
            )
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks');

        // Assert
        $this->assertEquals($tasks[1]->task_uuid, $response->json('0.uuid'));
    }

    /**
     * @test
     */
    public function it_shows_tasks_only_from_today()
    {
        // Arrange
        $tasks = StackkitCloudTaskFactory::new()
            ->count(2)
            ->sequence(
                ['created_at' => today()],
                ['created_at' => today()->subDay()],
            )
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks');

        // Assert
        $this->assertCount(1, $response->json());
    }

    /**
     * @test
     */
    public function it_can_filter_only_failed_tasks()
    {
        // Arrange
        StackkitCloudTaskFactory::new()
            ->count(2)
            ->sequence(
                ['status' => 'pending'],
                ['status' => 'failed'],
            )
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks?filter=failed');

        // Assert
        $this->assertCount(1, $response->json());
    }

    /**
     * @test
     */
    public function it_can_filter_tasks_created_at_exact_time()
    {
        // Arrange
        StackkitCloudTaskFactory::new()
            ->count(4)
            ->sequence(
                ['created_at' => now()->setTime(15,4, 59)],
                ['created_at' => now()->setTime(16, 5, 0)],
                ['created_at' => now()->setTime(16, 5, 59)],
                ['created_at' => now()->setTime(16, 6, 0)],
            )
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks?time=16:05');

        // Assert
        $this->assertCount(2, $response->json());
    }

    /**
     * @test
     */
    public function it_can_filter_tasks_created_at_exact_hour()
    {
        // Arrange
        StackkitCloudTaskFactory::new()
            ->count(4)
            ->sequence(
                ['created_at' => now()->setTime(15,59, 59)],
                ['created_at' => now()->setTime(16, 5, 59)],
                ['created_at' => now()->setTime(16, 32, 32)],
            )
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks?hour=16');

        // Assert
        $this->assertCount(2, $response->json());
    }

    /**
     * @test
     */
    public function it_can_filter_tasks_by_queue()
    {
        // Arrange
        StackkitCloudTaskFactory::new()
            ->count(3)
            ->sequence(
                ['queue' => 'barbequeue'],
                ['queue' => 'barbequeue-priority'],
                ['queue' => 'barbequeue-priority'],
            )
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks?queue=barbequeue-priority');

        // Assert
        $this->assertCount(2, $response->json());
    }

    /**
     * @test
     */
    public function it_can_filter_tasks_by_status()
    {
        // Arrange
        StackkitCloudTaskFactory::new()
            ->count(4)
            ->sequence(
                ['status' => 'queued'],
                ['status' => 'pending'],
                ['status' => 'failed'],
                ['status' => 'failed'],
            )
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks?status=failed');

        // Assert
        $this->assertCount(2, $response->json());
    }

    /**
     * @test
     */
    public function it_shows_max_100_tasks()
    {
        // Arrange
        StackkitCloudTaskFactory::new()
            ->count(101)
            ->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks');

        // Assert
        $this->assertCount(100, $response->json());
    }

    /**
     * @test
     */
    public function it_returns_the_correct_task_fields()
    {
        // Arrange
        $task = StackkitCloudTaskFactory::new()->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks');

        // Assert
        $this->assertEquals($task->task_uuid, $response->json('0.uuid'));
        $this->assertEquals($task->id, $response->json('0.id'));
        $this->assertEquals('SimpleJob', $response->json('0.name'));
        $this->assertEquals('queued', $response->json('0.status'));
        $this->assertEquals(0, $response->json('0.attempts'));
        $this->assertEquals('1 second ago', $response->json('0.created'));
        $this->assertEquals('barbequeue', $response->json('0.queue'));
    }

    /**
     * @test
     */
    public function it_returns_info_about_a_specific_task()
    {
        // Arrange
        $task = StackkitCloudTaskFactory::new()->create();

        // Act
        $response = $this->getJson('/cloud-tasks-api/task/' . $task->task_uuid);

        // Assert
        $this->assertEquals($task->id, $response['id']);
        $this->assertEquals('queued', $response['status']);
        $this->assertEquals('barbequeue', $response['queue']);
        $this->assertEquals([], $response['events']);
        $this->assertEquals('[]', $response['payload']);
        $this->assertEquals(null, $response['exception']);
    }

    /**
     * @test
     */
    public function when_a_job_is_dispatched_it_will_be_added_to_the_monitor()
    {
        // Arrange
        CloudTasksApi::fake();
        $tasksBefore = StackkitCloudTask::count();
        $job = $this->dispatch(new SimpleJob());
        $tasksAfter = StackkitCloudTask::count();

        // Assert
        $task = StackkitCloudTask::first();
        $this->assertSame(0, $tasksBefore);
        $this->assertSame(1, $tasksAfter);
        $this->assertDatabaseHas(StackkitCloudTask::class, [
            'queue' => 'barbequeue',
            'status' => 'queued',
            'name' => SimpleJob::class,
        ]);
        $payload = \Safe\json_decode($task->getMetadata()['payload'], true);
        $this->assertSame($payload, $job->payload);
    }

    /**
     * @test
     */
    public function when_a_job_is_running_it_will_be_updated_in_the_monitor()
    {
        // Arrange
        \Illuminate\Support\Carbon::setTestNow(now());
        CloudTasksApi::fake();
        OpenIdVerificator::fake();

        $this->dispatch(new SimpleJob())->run();

        // Assert
        $task = StackkitCloudTask::firstOrFail();
        $events = $task->getEvents();
        $this->assertCount(3, $events);
        $this->assertEquals(
            [
                'status' => 'running',
                'datetime' => now()->toDateTimeString(),
                'diff' => '1 second ago',
            ],
            $events[1]
        );
    }

    /**
     * @test
     */
    public function when_a_job_is_successful_it_will_be_updated_in_the_monitor()
    {
        // Arrange
        \Illuminate\Support\Carbon::setTestNow(now());
        CloudTasksApi::fake();
        OpenIdVerificator::fake();

        $this->dispatch(new SimpleJob())->run();

        // Assert
        $task = StackkitCloudTask::firstOrFail();
        $events = $task->getEvents();
        $this->assertCount(3, $events);
        $this->assertEquals(
            [
                'status' => 'successful',
                'datetime' => now()->toDateTimeString(),
                'diff' => '1 second ago',
            ],
            $events[2]
        );
    }

    /**
     * @test
     */
    public function when_a_job_errors_it_will_be_updated_in_the_monitor()
    {
        // Arrange
        \Illuminate\Support\Carbon::setTestNow(now());
        CloudTasksApi::fake();
        OpenIdVerificator::fake();

        $this->dispatch(new FailingJob())->run();

        // Assert
        $task = StackkitCloudTask::firstOrFail();
        $events = $task->getEvents();
        $this->assertCount(3, $events);
        $this->assertEquals(
            [
                'status' => 'error',
                'datetime' => now()->toDateTimeString(),
                'diff' => '1 second ago',
            ],
            $events[2]
        );
        $this->assertStringContainsString('Error: simulating a failing job', $task->getMetadata()['exception']);
    }

    /**
     * @test
     */
    public function when_a_job_fails_it_will_be_updated_in_the_monitor()
    {
        // Arrange
        \Illuminate\Support\Carbon::setTestNow(now());
        CloudTasksApi::fake();
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxAttempts(3)
        );

        $job = $this->dispatch(new FailingJob());
        $job->run();
        $job->run();
        $job->run();

        // Assert
        $task = StackkitCloudTask::firstOrFail();
        $events = $task->getEvents();
        $this->assertCount(7, $events);
        $this->assertEquals(
            [
                'status' => 'failed',
                'datetime' => now()->toDateTimeString(),
                'diff' => '1 second ago',
            ],
            $events[6]
        );
    }
}
