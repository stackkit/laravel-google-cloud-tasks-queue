<?php

declare(strict_types=1);

namespace Tests;

use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;

class CloudTasksApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $requiredEnvs = [
            'CI_CLOUD_TASKS_PROJECT_ID',
            'CI_CLOUD_TASKS_QUEUE',
            'CI_CLOUD_TASKS_LOCATION',
            'CI_CLOUD_TASKS_SERVICE_ACCOUNT_EMAIL',
            'CI_SERVICE_ACCOUNT_JSON_KEY',
        ];

        foreach ($requiredEnvs as $env) {
            if (! env($env)) {
                $this->fail('Missing ['.$env.'] environment variable.');
            }
        }

        $this->setConfigValue('project', env('CI_CLOUD_TASKS_PROJECT_ID'));
        $this->setConfigValue('queue', env('CI_CLOUD_TASKS_QUEUE'));
        $this->setConfigValue('location', env('CI_CLOUD_TASKS_LOCATION'));
        $this->setConfigValue('service_account_email', env('CI_CLOUD_TASKS_SERVICE_ACCOUNT_EMAIL'));

        $this->client = new CloudTasksClient();

    }

    /**
     * @test
     */
    public function test_create_task()
    {
        // Arrange
        $httpRequest = new HttpRequest();
        $httpRequest->setHttpMethod(HttpMethod::GET);
        $httpRequest->setUrl('https://example.com');

        $cloudTask = new Task();
        $cloudTask->setHttpRequest($httpRequest);

        // Act
        $task = CloudTasksApi::createTask(
            $this->client->queueName(
                env('CI_CLOUD_TASKS_PROJECT_ID'),
                env('CI_CLOUD_TASKS_LOCATION'),
                env('CI_CLOUD_TASKS_QUEUE')
            ),
            $cloudTask
        );
        $taskName = $task->getName();

        // Assert
        $this->assertMatchesRegularExpression(
            '/projects\/'.env('CI_CLOUD_TASKS_PROJECT_ID').'\/locations\/'.env('CI_CLOUD_TASKS_LOCATION').'\/queues\/'.env('CI_CLOUD_TASKS_QUEUE').'\/tasks\/\d+$/',
            $taskName
        );
    }

    /**
     * @test
     */
    public function test_delete_task_on_non_existing_task()
    {
        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Requested entity was not found.');

        // Act
        CloudTasksApi::deleteTask(
            $this->client->taskName(
                env('CI_CLOUD_TASKS_PROJECT_ID'),
                env('CI_CLOUD_TASKS_LOCATION'),
                env('CI_CLOUD_TASKS_QUEUE'),
                'non-existing-id'
            ),
        );

    }

    /**
     * @test
     */
    public function test_delete_task()
    {
        // Arrange
        $httpRequest = new HttpRequest();
        $httpRequest->setHttpMethod(HttpMethod::GET);
        $httpRequest->setUrl('https://example.com');

        $cloudTask = new Task();
        $cloudTask->setHttpRequest($httpRequest);
        $cloudTask->setScheduleTime(new Timestamp(['seconds' => time() + 10]));

        $task = CloudTasksApi::createTask(
            $this->client->queueName(
                env('CI_CLOUD_TASKS_PROJECT_ID'),
                env('CI_CLOUD_TASKS_LOCATION'),
                env('CI_CLOUD_TASKS_QUEUE')
            ),
            $cloudTask
        );

        // Act
        $fresh = CloudTasksApi::getTask($task->getName());
        $this->assertInstanceOf(Task::class, $fresh);

        CloudTasksApi::deleteTask($task->getName());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('NOT_FOUND');
        CloudTasksApi::getTask($task->getName());
    }
}
