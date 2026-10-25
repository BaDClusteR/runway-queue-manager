<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Exception\InvalidQueueNameException;
use Runway\QueueManager\QueueWorkerLauncher;
use Runway\QueueManager\Tests\Fixture\RecordingLauncher;
use Runway\QueueManager\Tests\Fixture\TestLogger;

class QueueWorkerLauncherTest extends TestCase {
    protected TestLogger $logger;

    protected function setUp(): void {
        $this->logger = new TestLogger();
    }

    /**
     * @return array<array-key, array{mixed}>
     */
    public static function disabledProvider(): array {
        return [
            'false'          => [false],
            '"false"'        => ['false'],
            '"0"'            => ['0'],
            '0'              => [0],
            '"off"'          => ['off'],
            '"no"'           => ['no'],
        ];
    }

    /**
     * @return array<array-key, array{mixed}>
     */
    public static function enabledProvider(): array {
        return [
            'true'           => [true],
            '"true"'         => ['true'],
            '"1"'            => ['1'],
            '"on"'           => ['on'],
            // Not set.
            'null'           => [null],
            'empty'          => [''],
            // Not a boolean: the default.
            'other string'   => ['sometimes'],
            'not a scalar'   => [[false]],
        ];
    }

    #[DataProvider('disabledProvider')]
    public function testDisabled(mixed $enabled): void {
        $launcher = $this->createLauncher(enabled: $enabled);

        self::assertFalse($launcher->launch());
        self::assertSame([], $launcher->commands);
        self::assertSame([], $this->logger->records);
    }

    #[DataProvider('enabledProvider')]
    public function testEnabled(mixed $enabled): void {
        $launcher = $this->createLauncher(enabled: $enabled);

        self::assertTrue($launcher->launch());
        self::assertCount(1, $launcher->commands);
        self::assertSame([], $this->logger->records);
    }

    public function testEnabledByDefault(): void {
        $launcher = new RecordingLauncher($this->logger);

        self::assertTrue($launcher->launch());
        self::assertCount(1, $launcher->commands);
    }

    public function testCommand(): void {
        $launcher = $this->createLauncher('/opt/php 8/bin/php', "/var/www/it's/console");

        $launcher->launch();
        $launcher->launch('media.thumbs');

        self::assertSame(
            [
                escapeshellarg('/opt/php 8/bin/php') . ' ' . escapeshellarg("/var/www/it's/console")
                . ' queue:work > /dev/null 2>&1 &',
                escapeshellarg('/opt/php 8/bin/php') . ' ' . escapeshellarg("/var/www/it's/console")
                . ' queue:work --queue=' . escapeshellarg('media.thumbs') . ' > /dev/null 2>&1 &',
            ],
            $launcher->commands
        );
    }

    public function testDefaultPaths(): void {
        foreach ([null, ''] as $empty) {
            $launcher = $this->createLauncher($empty, $empty);

            $launcher->launch();

            self::assertSame(
                [
                    escapeshellarg(PHP_BINDIR . '/php') . ' ' . escapeshellarg(PROJECT_ROOT . '/console')
                    . ' queue:work > /dev/null 2>&1 &',
                ],
                $launcher->commands
            );
        }
    }

    /**
     * The ENV values come typed: e.g. a path of digits is an integer, an array is not a path.
     */
    public function testTypedPhpBinary(): void {
        $launcher = $this->createLauncher(8);
        $launcher->launch();
        self::assertStringStartsWith(escapeshellarg('8') . ' ', $launcher->commands[0]);

        $launcher = $this->createLauncher(['/usr/bin/php']);
        $launcher->launch();
        self::assertStringStartsWith(escapeshellarg(PHP_BINDIR . '/php') . ' ', $launcher->commands[0]);
    }

    public function testInvalidQueue(): void {
        $launcher = $this->createLauncher();

        try {
            $launcher->launch('a; rm -rf /');
            self::fail('The queue name is checked.');
        } catch (InvalidQueueNameException) {
            self::assertSame([], $launcher->commands);
        }
    }

    public function testFailedLaunch(): void {
        $launcher = $this->createLauncher('/usr/bin/php', '/app/console');
        $launcher->exitCode = 127;

        self::assertFalse($launcher->launch());
        self::assertSame(
            [[
                'warning',
                "The queue worker cannot be started: \"{$launcher->commands[0]}\" exited with code 127.",
                [],
            ]],
            $this->logger->records
        );
    }

    public function testWindows(): void {
        $launcher = $this->createLauncher();
        $launcher->isWindows = true;

        self::assertFalse($launcher->launch());
        self::assertSame([], $launcher->commands);
        self::assertSame(
            [['warning', 'The queue worker cannot be started on Windows: run "queue:work" by a schedule.', []]],
            $this->logger->records
        );
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function testRealWindowsDetection(): void {
        self::assertFalse(new QueueWorkerLauncher($this->logger)->launch());
    }

    protected function createLauncher(
        mixed   $phpBinary = '/usr/bin/php',
        ?string $consolePath = '/app/console',
        mixed   $enabled = true
    ): RecordingLauncher {
        return new RecordingLauncher($this->logger, $enabled, $phpBinary, $consolePath);
    }
}
