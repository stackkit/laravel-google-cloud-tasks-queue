<?php

namespace Tests;

use Error;
use Stackkit\LaravelGoogleCloudTasksQueue\Errors;
use Tests\Support\SimpleJob;

class ConfigTest extends TestCase
{
    /** @test */
    public function credentials_are_required()
    {
        $this->setConfigValue('credentials', '');

        $this->expectException(Error::class);
        $this->expectExceptionMessage(Errors::invalidCredentials());

        SimpleJob::dispatch();
    }

    /** @test */
    public function credentials_file_must_exist()
    {
        $this->setConfigValue('credentials', 'doesnotexist.json');

        $this->expectException(Error::class);
        $this->expectExceptionMessage(Errors::credentialsFileDoesNotExist());

        SimpleJob::dispatch();
    }

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
}
