<?php

namespace Tests;

use Google\Cloud\Tasks\V2\RetryConfig;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\OpenIdVerificator;
use Stackkit\LaravelGoogleCloudTasksQueue\StackkitCloudTask;
use Tests\Support\FailingJob;
use Tests\Support\JobThatWillBeReleased;
use Tests\Support\SimpleJob;

class CloudTasksDashboardTest extends TestCase
{
    /**
     * @test
     */
    public function test_loading_dashboard_works()
    {
        // Arrange
        factory(StackkitCloudTask::class)->create();

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

        factory(StackkitCloudTask::class)->create(['status' => 'queued', 'created_at' => $thisMinute]);
        factory(StackkitCloudTask::class)->create(['status' => 'queued', 'created_at' => $thisHour]);
        factory(StackkitCloudTask::class)->create(['status' => 'queued', 'created_at' => $thisDay]);
        factory(StackkitCloudTask::class)->create(['status' => 'queued', 'created_at' => $lastMinute]);

        factory(StackkitCloudTask::class)->create(['status' => 'failed', 'created_at' => $thisMinute]);
        factory(StackkitCloudTask::class)->create(['status' => 'failed', 'created_at' => $thisHour]);
        factory(StackkitCloudTask::class)->create(['status' => 'failed', 'created_at' => $thisDay]);
        factory(StackkitCloudTask::class)->create(['status' => 'failed', 'created_at' => $lastMinute]);

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
        factory(StackkitCloudTask::class)->create(['created_at' => now()->subMinute()]);
        $task = factory(StackkitCloudTask::class)->create(['created_at' => now()]);

        // Act
        $response = $this->getJson('/cloud-tasks-api/tasks');

        // Assert
        $this->assertEquals($task->task_uuid, $response->json('0.uuid'));
    }

    /**
     * @test
     */
    public function it_shows_tasks_only_from_today()
    {
        // Arrange
        factory(StackkitCloudTask::class)->create(['created_at' => today()]);
        factory(StackkitCloudTask::class)->create(['created_at' => today()->subDay()]);

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
        factory(StackkitCloudTask::class)->create(['status' => 'pending']);
        factory(StackkitCloudTask::class)->create(['status' => 'failed']);

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
        factory(StackkitCloudTask::class)->create(['created_at' => now()->setTime(15,4, 59)]);
        factory(StackkitCloudTask::class)->create(['created_at' => now()->setTime(16,5, 0)]);
        factory(StackkitCloudTask::class)->create(['created_at' => now()->setTime(16,5, 59)]);
        factory(StackkitCloudTask::class)->create(['created_at' => now()->setTime(16,6, 0)]);

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
        factory(StackkitCloudTask::class)->create(['created_at' => now()->setTime(15,59, 59)]);
        factory(StackkitCloudTask::class)->create(['created_at' => now()->setTime(16,5, 59)]);
        factory(StackkitCloudTask::class)->create(['created_at' => now()->setTime(16,32, 32)]);

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
        factory(StackkitCloudTask::class)->create(['queue' => 'barbequeue']);
        factory(StackkitCloudTask::class)->create(['queue' => 'barbequeue-priority']);
        factory(StackkitCloudTask::class)->create(['queue' => 'barbequeue-priority']);

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
        factory(StackkitCloudTask::class)->create(['status' => 'queued']);
        factory(StackkitCloudTask::class)->create(['status' => 'pending']);
        factory(StackkitCloudTask::class)->create(['status' => 'failed']);
        factory(StackkitCloudTask::class)->create(['status' => 'failed']);

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
        factory(StackkitCloudTask::class)->times(101)->create();

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
        $task = factory(StackkitCloudTask::class)->create();

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
        $task = factory(StackkitCloudTask::class)->create();

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
     *
     * @testWith [{"task_type": "http"}]
     *           [{"task_type": "appengine"}]
     */
    public function when_a_job_is_dispatched_it_will_be_added_to_the_dashboard(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

        CloudTasksApi::fake();
        $tasksBefore = StackkitCloudTask::count();
        $job = $this->dispatch(new SimpleJob());
        $tasksAfter = StackkitCloudTask::count();

        // Assert
        $task = StackkitCloudTask::first();
        $this->assertSame(0, $tasksBefore);
        $this->assertSame(1, $tasksAfter);
        $this->assertDatabaseHas((new StackkitCloudTask())->getTable(), [
            'queue' => 'barbequeue',
            'status' => 'queued',
            'name' => SimpleJob::class,
        ]);
        $this->assertSame($task->getMetadata()['payload'], $job->payload);
    }

    /**
     * @test
     */
    public function when_dashboard_is_disabled_jobs_will_not_be_added_to_the_dashboard()
    {
        // Arrange
        CloudTasksApi::fake();
        config()->set('cloud-tasks.dashboard.enabled', false);

        // Act
        $this->dispatch(new SimpleJob());

        // Assert
        $this->assertDatabaseCount((new StackkitCloudTask())->getTable(), 0);
    }

    /**
     * @test
     *
     * @testWith [{"task_type": "http"}]
     *           [{"task_type": "appengine"}]
     */
    public function when_a_job_is_scheduled_it_will_be_added_as_such(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

        CloudTasksApi::fake();
        Carbon::setTestNow(now());
        $tasksBefore = StackkitCloudTask::count();

        $job = $this->dispatch((new SimpleJob())->delay(now()->addSeconds(10)));
        $tasksAfter = StackkitCloudTask::count();

        // Assert
        $task = StackkitCloudTask::first();
        $this->assertSame(0, $tasksBefore);
        $this->assertSame(1, $tasksAfter);
        $this->assertDatabaseHas((new StackkitCloudTask())->getTable(), [
            'queue' => 'barbequeue',
            'status' => 'scheduled',
            'name' => SimpleJob::class,
        ]);
        $this->assertEquals(now()->addSeconds(10)->toDateTimeString(), $task->getEvents()[0]['scheduled_at']);
    }

    /**
     * @test
     *
     * @testWith [{"task_type": "http"}]
     *           [{"task_type": "appengine"}]
     */
    public function when_a_job_is_running_it_will_be_updated_in_the_dashboard(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

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
     *
     *  @testWith [{"task_type": "http"}]
     *            [{"task_type": "appengine"}]
     */
    public function when_a_job_is_successful_it_will_be_updated_in_the_dashboard(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

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
     *
     *  @testWith [{"task_type": "http"}]
     *            [{"task_type": "appengine"}]
     */
    public function when_a_job_errors_it_will_be_updated_in_the_dashboard(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

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
     *
     *  @testWith [{"task_type": "http"}]
     *            [{"task_type": "appengine"}]
     */
    public function when_a_job_fails_it_will_be_updated_in_the_dashboard(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

        \Illuminate\Support\Carbon::setTestNow(now());
        CloudTasksApi::fake();
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxAttempts(3)
        );

        $job = $this->dispatch(new FailingJob());
        $releasedJob = $job->runAndGetReleasedJob();
        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $releasedJob->run();

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

    /**
     * @test
     *
     *   @testWith [{"task_type": "http"}]
     *             [{"task_type": "appengine"}]
     */
    public function when_a_job_is_released_it_will_be_updated_in_the_dashboard(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

        \Illuminate\Support\Carbon::setTestNow(now());
        CloudTasksApi::fake();
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxAttempts(3)
        );

        $this->dispatch(new JobThatWillBeReleased())->run();

        // Assert
        $task = StackkitCloudTask::firstOrFail();
        $events = $task->getEvents();

        $this->assertCount(3, $events);
        $this->assertEquals(
            [
                'status' => 'released',
                'datetime' => now()->toDateTimeString(),
                'diff' => '1 second ago',
                'delay' => 0,
            ],
            $events[2]
        );
    }

    /**
     * @test
     *
     *   @testWith [{"task_type": "http"}]
     *             [{"task_type": "appengine"}]
     */
    public function job_release_delay_is_added_to_the_metadata(array $test)
    {
        // Arrange
        $this->withTaskType($test['task_type']);

        \Illuminate\Support\Carbon::setTestNow(now());
        CloudTasksApi::fake();
        OpenIdVerificator::fake();
        CloudTasksApi::partialMock()->shouldReceive('getRetryConfig')->andReturn(
            (new RetryConfig())->setMaxAttempts(3)
        );

        $this->dispatch(new JobThatWillBeReleased(15))->run();

        // Assert
        $task = StackkitCloudTask::firstOrFail();
        $events = $task->getEvents();

        $this->assertCount(3, $events);
        $this->assertEquals(
            [
                'status' => 'released',
                'datetime' => now()->toDateTimeString(),
                'diff' => '1 second ago',
                'delay' => 15,
            ],
            $events[2]
        );
    }

    /**
     * @test
     */
    public function test_publish()
    {
        // Arrange
        config()->set('cloud-tasks.dashboard.enabled', true);

        // Act & Assert
        $expectedPublishBase = dirname(__DIR__);

        if (version_compare(app()->version(), '9.0.0', '>=')) {
            $this->artisan('vendor:publish --tag=cloud-tasks --force')
                ->expectsOutputToContain('Publishing [cloud-tasks] assets.')
                ->expectsOutputToContain('Copying file [' . $expectedPublishBase . '/config/cloud-tasks.php] to [config/cloud-tasks.php]')
                ->expectsOutputToContain('Copying directory [' . $expectedPublishBase . '/dashboard/dist] to [public/vendor/cloud-tasks]');
        } else {
            $this->artisan('vendor:publish --tag=cloud-tasks --force')
                ->expectsOutput('Copied File [' . $expectedPublishBase . '/config/cloud-tasks.php] To [/config/cloud-tasks.php]')
                ->expectsOutput('Copied Directory [' . $expectedPublishBase . '/dashboard/dist] To [/public/vendor/cloud-tasks]')
                ->expectsOutput('Publishing complete.');
        }
    }

    /**
     * @test
     */
    public function when_dashboard_is_enabled_it_adds_the_necessary_routes()
    {
        // Act
        $routes = app(Router::class)->getRoutes();

        // Assert
        $this->assertInstanceOf(Route::class, $routes->getByName('cloud-tasks.handle-task'));
        $this->assertInstanceOf(Route::class, $routes->getByName('cloud-tasks.index'));
        $this->assertInstanceOf(Route::class, $routes->getByName('cloud-tasks.api.dashboard'));
        $this->assertInstanceOf(Route::class, $routes->getByName('cloud-tasks.api.tasks'));
        $this->assertInstanceOf(Route::class, $routes->getByName('cloud-tasks.api.task'));
    }

    /**
     * @test
     */
    public function when_dashboard_is_enabled_it_adds_the_necessary_migrations()
    {
        $this->assertTrue(in_array(dirname(__DIR__) . '/src/../migrations', app('migrator')->paths()));
    }

    /**
     * @test
     */
    public function when_dashboard_is_disabled_it_adds_the_necessary_migrations()
    {
        $this->assertEmpty(app('migrator')->paths());
    }

    /**
     * @test
     */
    public function when_dashboard_is_disabled_it_does_not_add_the_dashboard_routes()
    {
        // Act
        $routes = app(Router::class)->getRoutes();

        // Assert
        $this->assertInstanceOf(Route::class, $routes->getByName('cloud-tasks.handle-task'));
        $this->assertNull($routes->getByName('cloud-tasks.index'));
        $this->assertNull($routes->getByName('cloud-tasks.api.dashboard'));
        $this->assertNull($routes->getByName('cloud-tasks.api.tasks'));
        $this->assertNull($routes->getByName('cloud-tasks.api.task'));
    }

    /**
     * @test
     */
    public function dashboard_is_password_protected()
    {
        // Arrange
        $this->defaultHeaders['Authorization'] = '';

        // Act
        $response = $this->getJson('/cloud-tasks-api/dashboard');

        // Assert
        $this->assertEquals(403, $response->status());
    }

    /**
     * @test
     */
    public function can_enter_with_token()
    {
        // Arrange
        $this->defaultHeaders['Authorization'] = 'Bearer ' . encrypt(time() + 10);

        // Act
        $response = $this->getJson('/cloud-tasks-api/dashboard');

        // Assert
        $this->assertEquals(200, $response->status());
    }

    /**
     * @test
     */
    public function token_can_expire()
    {
        // Arrange
        $this->defaultHeaders['Authorization'] = 'Bearer ' . encrypt(Carbon::create(2020, 5, 15, 15, 15, 15)->timestamp);

        // Act & Assert
        Carbon::setTestNow(Carbon::create(2020, 5, 15, 15, 15, 14));
        $this->assertEquals(200, $this->getJson('/cloud-tasks-api/dashboard')->status());
        Carbon::setTestNow(Carbon::create(2020, 5, 15, 15, 15, 15));
        $this->assertEquals(403, $this->getJson('/cloud-tasks-api/dashboard')->status());
    }

    /**
     * @test
     */
    public function there_is_a_login_endpoint()
    {
        // Arrange
        Carbon::setTestNow($now = now());
        config()->set('cloud-tasks.dashboard.password', 'test123');

        // Act
        $invalidPassword = $this->postJson('/cloud-tasks-api/login', ['password' => 'hey']);
        $validPassword = $this->postJson('/cloud-tasks-api/login', ['password' => 'test123']);

        // Assert
        $this->assertSame('', $invalidPassword->content());
        $this->assertStringStartsWith('ey', $validPassword->content());
        $validUntil = decrypt($validPassword->content());

        // the token should be valid for 15 minutes.
        $this->assertSame($now->timestamp + 900, $validUntil);
    }
}
