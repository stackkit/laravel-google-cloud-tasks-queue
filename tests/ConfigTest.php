<?php

namespace Tests;

use Error;
use Stackkit\LaravelGoogleCloudTasksQueue\Errors;
use Tests\Support\SimpleJob;

class ConfigTest extends TestCase
{
    /** @test */
    public function project_is_required()
    {
        $this->setConfigValue('project', '');

        $this->expectException(Error::class);
        $this->expectExceptionMessage(Errors::invalidProject());

        SimpleJob::dispatch();
    }

    /** @test */
    public function location_is_required()
    {
        $this->setConfigValue('location', '');

        $this->expectException(Error::class);
        $this->expectExceptionMessage(Errors::invalidLocation());

        SimpleJob::dispatch();
    }

    /** @test */
    public function handler_is_required()
    {
        $this->setConfigValue('handler', '');

        $this->expectException(Error::class);
        $this->expectExceptionMessage(Errors::invalidHandler());

        SimpleJob::dispatch();
    }

    /** @test */
    public function service_email_is_required()
    {
        $this->setConfigValue('service_account_email', '');

        $this->expectException(Error::class);
        $this->expectExceptionMessage(Errors::invalidServiceAccountEmail());

        SimpleJob::dispatch();
    }
}
