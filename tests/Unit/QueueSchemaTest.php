<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Exception\UnsupportedDatabaseException;
use Runway\QueueManager\Storage\QueueSchema;
use Runway\QueueManager\Tests\Fixture\FakeStorage;
use Runway\QueueManager\Tests\Fixture\RecordingDriver;
use Runway\QueueManager\Tests\Fixture\TestStorage;

class QueueSchemaTest extends TestCase {
    public function testSqliteStatements(): void {
        $statements = new QueueSchema(new FakeStorage(new RecordingDriver(), 'sqlite'))->getStatements();

        self::assertCount(2, $statements);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `{jobs}` (', $statements[0]);
        self::assertStringEndsWith(') STRICT', $statements[0]);
        self::assertSame(
            'CREATE INDEX IF NOT EXISTS `{jobs}_queue_status_IDX` ON `{jobs}` (queue, status, id)',
            $statements[1]
        );

        foreach ($statements as $statement) {
            // The comments are dropped, the statements are split at ";".
            self::assertStringNotContainsString('--', $statement);
            self::assertStringNotContainsString(';', $statement);
        }
    }

    public function testMysqlStatements(): void {
        $statements = new QueueSchema(new FakeStorage(new RecordingDriver(), 'mysql'))->getStatements();

        self::assertCount(1, $statements);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `{jobs}` (', $statements[0]);
        self::assertStringContainsString('KEY `{jobs}_queue_status_IDX` (queue, status, id)', $statements[0]);
        self::assertStringEndsWith('COLLATE=utf8mb4_unicode_ci', $statements[0]);
    }

    public function testOtherTableName(): void {
        $statements = new QueueSchema(new FakeStorage(new RecordingDriver(), 'sqlite', 'tasks'))->getStatements();

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `{tasks}` (', $statements[0]);
        self::assertSame(
            'CREATE INDEX IF NOT EXISTS `{tasks}_queue_status_IDX` ON `{tasks}` (queue, status, id)',
            $statements[1]
        );
    }

    public function testUnsupportedDatabase(): void {
        $this->expectException(UnsupportedDatabaseException::class);
        $this->expectExceptionMessage('The queue does not support "pgsql" databases, only: sqlite, mysql');

        new QueueSchema(new FakeStorage(new RecordingDriver(), 'pgsql'))->install();
    }

    public function testMysqlUpgrade(): void {
        $driver = new RecordingDriver([
            'SHOW COLUMNS FROM `{jobs}`' => [['Field' => 'id'], ['Field' => 'attempts'], ['Field' => 'queue']],
        ]);
        $schema = new QueueSchema(new FakeStorage($driver, 'mysql'));

        $schema->install();

        self::assertSame(
            [
                ...$schema->getStatements(),
                'ALTER TABLE `{jobs}` ADD COLUMN available INT UNSIGNED NOT NULL DEFAULT 0',
            ],
            $driver->executed
        );
    }

    public function testSqliteUpgrade(): void {
        $driver = new RecordingDriver(['PRAGMA table_info(`{jobs}`)' => [['name' => 'id'], ['name' => 'available']]]);
        $schema = new QueueSchema(new FakeStorage($driver, 'sqlite'));

        $schema->install();

        self::assertSame(
            [...$schema->getStatements(), 'ALTER TABLE `{jobs}` ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0'],
            $driver->executed
        );
    }

    public function testInstallCreatesTableOnce(): void {
        $storage = TestStorage::sqlite('tasks', 'x_');
        $schema = new QueueSchema($storage);
        $driver = $storage->getDriver();
        $driver->execute("INSERT INTO {$storage->getTable()} (type, created, updated) VALUES ('a', 1, 1)");

        $schema->install();

        self::assertSame(1, $driver->getFirstScalarResult("SELECT COUNT(*) FROM {$storage->getTable()}"));
        self::assertSame(
            ['id', 'queue', 'type', 'status', 'payload', 'progress', 'result', 'error', 'is_cancel_requested',
                'attempts', 'available', 'created', 'started', 'finished', 'updated'],
            array_column($driver->getResult('PRAGMA table_info(`x_tasks`)'), 'name')
        );
    }
}
