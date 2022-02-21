<?php

namespace Tests;

use Factories\StackkitCloudTaskFactory;

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
}
