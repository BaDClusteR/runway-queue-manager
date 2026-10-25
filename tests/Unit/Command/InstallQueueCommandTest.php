<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Command\InstallQueueCommand;
use Runway\QueueManager\Storage\QueueSchema;
use Runway\QueueManager\Tests\Fixture\FakeStorage;
use Runway\QueueManager\Tests\Fixture\RecordingDriver;
use Runway\QueueManager\Tests\Support\CommandRunner;

class InstallQueueCommandTest extends TestCase {
    public function testDefinition(): void {
        $storage = new FakeStorage(new RecordingDriver(), 'sqlite');
        $command = new InstallQueueCommand(new QueueSchema($storage), $storage);

        self::assertSame('queue:install', $command->getName());
        self::assertSame(
            'Create the queue table, unless it exists (SQLite, MySQL / MariaDB)',
            $command->getDescription()
        );
        self::assertSame('Queue', $command->getGroup());
    }

    public function testInstallToDatabaseOfApplication(): void {
        $driver = new RecordingDriver([
            'SHOW COLUMNS FROM `{jobs}`' => [['Field' => 'attempts'], ['Field' => 'available']],
        ]);
        $storage = new FakeStorage($driver, 'mysql');

        [$exitCode, $output] = CommandRunner::run(new InstallQueueCommand(new QueueSchema($storage), $storage));

        self::assertSame(0, $exitCode);
        self::assertSame("The queue table is ready (mysql, the database of the application).\n", $output->stdout);
        self::assertCount(1, $driver->executed);
    }

    public function testInstallToOwnDatabase(): void {
        $driver = new RecordingDriver([
            'PRAGMA table_info(`{jobs}`)' => [['name' => 'attempts'], ['name' => 'available']],
        ]);
        $storage = new FakeStorage($driver, 'sqlite', isOwnDatabase: true);

        [, $output] = CommandRunner::run(new InstallQueueCommand(new QueueSchema($storage), $storage));

        self::assertSame("The queue table is ready (sqlite, QUEUE_DSN).\n", $output->stdout);
    }

    public function testPrint(): void {
        $driver = new RecordingDriver();
        $storage = new FakeStorage($driver, 'sqlite');
        $schema = new QueueSchema($storage);

        [$exitCode, $output] = CommandRunner::run(new InstallQueueCommand($schema, $storage), ['--print']);

        self::assertSame(0, $exitCode);
        self::assertSame(implode('', array_map(
            static fn(string $statement): string => "$statement;\n\n",
            $schema->getStatements()
        )), $output->stdout);
        self::assertSame([], $driver->executed);
    }
}
