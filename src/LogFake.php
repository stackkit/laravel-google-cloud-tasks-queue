<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use PHPUnit\Framework\Assert as PHPUnit;
use Psr\Log\LoggerInterface;

class LogFake
{
    private array $loggedMessages = [];

    public function emergency(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function alert(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function critical(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function error(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function warning(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function notice(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function info(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    /**
     * @param string $level
     */
    public function log($level, string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function channel(): self
    {
        return $this;
    }

    public function assertLogged(string $message): void
    {
        PHPUnit::assertTrue(in_array($message, $this->loggedMessages), 'The message [' . $message . '] was not logged.');
    }

    public function assertNotLogged(string $message): void
    {
        PHPUnit::assertTrue(
            ! in_array($message, $this->loggedMessages),
            'The message [' . $message . '] was logged.'
        );
    }
}
