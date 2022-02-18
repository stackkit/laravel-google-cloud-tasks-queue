<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use PHPUnit\Framework\Assert as PHPUnit;
use Psr\Log\LoggerInterface;

class LogFake implements LoggerInterface
{
    private array $loggedMessages = [];

    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->loggedMessages[] = $message;
    }

    public function channel()
    {
        return $this;
    }

    public function assertLogged(string $message)
    {
        PHPUnit::assertTrue(in_array($message, $this->loggedMessages), 'The message [' . $message . '] was not logged.');
    }
}
