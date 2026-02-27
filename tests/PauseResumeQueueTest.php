<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Artisan;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;

class PauseResumeQueueTest extends TestCase
{
    #[Test]
    public function queue_can_be_paused(): void
    {
        // Arrange
        if (app()->version() < 12) {
            $this->markTestSkipped('This feature only exists in Laravel 12 and up.');
        }

        CloudTasksApi::fake();

        // $this->artisan('queue:pause cloudtasks:barbequeue');
        Artisan::call('queue:pause my-cloudtasks-connection:barbequeue');

        // Assert
        CloudTasksApi::assertQueuePaused('barbequeue');
    }

    #[Test]
    public function queue_can_be_resumed(): void
    {
        // Arrange
        if (app()->version() < 12) {
            $this->markTestSkipped('This feature only exists in Laravel 12 and up.');
        }

        CloudTasksApi::fake();

        Artisan::call('queue:pause my-cloudtasks-connection:barbequeue');
        Artisan::call('queue:continue my-cloudtasks-connection:barbequeue');

        // Assert
        CloudTasksApi::assertQueueNotPaused('barbequeue');
    }
}
