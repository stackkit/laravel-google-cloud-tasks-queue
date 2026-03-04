<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Artisan;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;

class PauseResumeQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (version_compare(app()->version(), '12.0.0', '<')) {
            $this->markTestSkipped('This feature only exists in Laravel 12 and up.');
        }

        CloudTasksApi::fake();
    }

    #[Test]
    public function queue_can_be_paused(): void
    {
        Artisan::call('queue:pause my-cloudtasks-connection:barbequeue');

        CloudTasksApi::assertQueuePaused('projects/my-test-project/locations/europe-west6/queues/barbequeue');
    }

    #[Test]
    public function queue_can_be_resumed(): void
    {
        Artisan::call('queue:pause my-cloudtasks-connection:barbequeue');
        CloudTasksApi::assertQueuePaused('projects/my-test-project/locations/europe-west6/queues/barbequeue');

        Artisan::call('queue:continue my-cloudtasks-connection:barbequeue');
        CloudTasksApi::assertQueueNotPaused('projects/my-test-project/locations/europe-west6/queues/barbequeue');
    }
}
