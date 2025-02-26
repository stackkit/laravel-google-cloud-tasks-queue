<?php

declare(strict_types=1);

namespace Tests;

use Google\Protobuf\Timestamp;
use Google\Cloud\Tasks\V2\Task;
use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use PHPUnit\Framework\Attributes\Test;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
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

        $this->client = new CloudTasksClient;

    }

    #[Test]
    public function custom_client_options_can_be_added()
    {
        // Arrange
        config()->set('cloud-tasks.client_options', [
            'credentials' => __DIR__.'/Support/gcloud-key-dummy.json',
        ]);

        // Act
        $export = var_export(app(CloudTasksClient::class), true);

        // Assert

        // CloudTasksClient makes it a bit difficult to read its properties, so this will have to do...
        $this->assertStringContainsString('info@stackkit.io', $export);
        $this->assertStringContainsString('PRIVATE KEY', $export);
    }

    #[Test]
    public function test_create_task()
    {
        // Arrange
        $httpRequest = new HttpRequest;
        $httpRequest->setHttpMethod(HttpMethod::GET);
        $httpRequest->setUrl('https://example.com');

        $cloudTask = new Task;
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

    #[Test]
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

    #[Test]
    public function test_delete_task()
    {
        // Arrange
        $httpRequest = new HttpRequest;
        $httpRequest->setHttpMethod(HttpMethod::GET);
        $httpRequest->setUrl('https://example.com');

        $cloudTask = new Task;
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
