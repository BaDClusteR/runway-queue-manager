<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Runway\Logger\Enum\LogLevelEnum;
use Runway\Logger\ILogger;

/**
 * Remembers the records: [level, message, context].
 */
final class TestLogger implements ILogger {
    /** @var array{string, string, array<mixed>}[] */
    public array $records = [];

    public function setLogLevel(LogLevelEnum $level): static {
        return $this;
    }

    public function debug(string $message, array $context = []): void {
        $this->records[] = ['debug', $message, $context];
    }

    public function info(string $message, array $context = []): void {
        $this->records[] = ['info', $message, $context];
    }

    public function deprecated(string $message, array $context = []): void {
        $this->records[] = ['deprecated', $message, $context];
    }

    public function warning(string $message, array $context = []): void {
        $this->records[] = ['warning', $message, $context];
    }

    public function error(string $message, array $context = []): void {
        $this->records[] = ['error', $message, $context];
    }

    public function critical(string $message, array $context = []): void {
        $this->records[] = ['critical', $message, $context];
    }

    public function emergency(string $message, array $context = []): void {
        $this->records[] = ['emergency', $message, $context];
    }
}
