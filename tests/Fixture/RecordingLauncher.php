<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Runway\QueueManager\QueueWorkerLauncher;

/**
 * Remembers the commands instead of running them (the real launch is in tests/E2E).
 */
final class RecordingLauncher extends QueueWorkerLauncher {
    /** @var string[] */
    public array $commands = [];

    public int $exitCode = 0;

    public bool $isWindows = false;

    protected function execute(string $command): int {
        $this->commands[] = $command;

        return $this->exitCode;
    }

    protected function isWindows(): bool {
        return $this->isWindows;
    }
}
