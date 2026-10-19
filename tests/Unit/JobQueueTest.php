<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\Exception\InvalidQueueNameException;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Tests\Fixture\TestStorage;

/**
 * Every test runs on SQLite, and on MySQL / MariaDB if QUEUE_TEST_MYSQL_DSN is set (see TestStorage).
 */
class JobQueueTest extends TestCase {
    public static function storageProvider(): array {
        return [
            'sqlite' => ['sqlite'],
            'mysql'  => ['mysql'],
        ];
    }

    #[DataProvider('storageProvider')]
    public function testEnqueue(string $database): void {
        $queue = $this->createQueue($database);

        $job = $queue->enqueue('export', ['password' => 'secret', 'name' => 'Тест']);

        self::assertSame('main', $job->queue);
        self::assertSame('export', $job->type);
        self::assertSame(JobStatus::QUEUED, $job->status);
        self::assertSame(['password' => 'secret', 'name' => 'Тест'], $job->payload);
        self::assertNull($job->progress);
        self::assertFalse($job->isCancelRequested);
        self::assertEquals($job, $queue->get($job->id));
        self::assertSame('other', $queue->enqueue('export', [], 'other')->queue);
    }

    #[DataProvider('storageProvider')]
    public function testInvalidQueueName(string $database): void {
        $this->expectException(InvalidQueueNameException::class);

        $this->createQueue($database)->enqueue('export', [], '../main');
    }

    #[DataProvider('storageProvider')]
    public function testClaimNextByQueue(string $database): void {
        $queue = $this->createQueue($database);
        $first = $queue->enqueue('a');
        $other = $queue->enqueue('b', [], 'other');
        $second = $queue->enqueue('c');

        $claimed = $queue->claimNext();

        self::assertSame($first->id, $claimed->id);
        self::assertSame(JobStatus::RUNNING, $claimed->status);
        self::assertNotNull($claimed->started);
        self::assertSame($other->id, $queue->claimNext(['other'])->id);
        self::assertSame($second->id, $queue->claimNext(['main', 'other'])->id);
        self::assertNull($queue->claimNext(['main', 'other']));
    }

    #[DataProvider('storageProvider')]
    public function testCancelledJobIsNotClaimed(string $database): void {
        $queue = $this->createQueue($database);
        $cancelled = $queue->enqueue('a');
        $next = $queue->enqueue('b');

        self::assertTrue($queue->requestCancel($cancelled->id));
        self::assertSame(JobStatus::CANCELLED, $queue->get($cancelled->id)->status);
        self::assertSame($next->id, $queue->claimNext()->id);
    }

    #[DataProvider('storageProvider')]
    public function testCancelOfRunningJob(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a');
        $queue->claimNext();

        self::assertTrue($queue->requestCancel($job->id));
        self::assertTrue($queue->isCancelRequested($job->id));
        // The handler stops: the job is cancelled, and cannot be cancelled again.
        $queue->markCancelled($job->id);
        self::assertSame(JobStatus::CANCELLED, $queue->get($job->id)->status);
        self::assertFalse($queue->requestCancel($job->id));
        self::assertFalse($queue->requestCancel(12345));
    }

    #[DataProvider('storageProvider')]
    public function testOnlyRunningJobIsFinished(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a');

        $queue->complete($job->id, ['size' => 1]);
        self::assertSame(JobStatus::QUEUED, $queue->get($job->id)->status);

        $queue->claimNext();
        $queue->setProgress($job->id, ['done' => 5, 'total' => 10]);
        $queue->complete($job->id, ['size' => 1]);
        $queue->fail($job->id, 'Too late');

        $done = $queue->get($job->id);
        self::assertSame(JobStatus::DONE, $done->status);
        self::assertSame(['size' => 1], $done->result);
        self::assertSame(['done' => 5, 'total' => 10], $done->progress);
        self::assertNull($done->error);
        self::assertNotNull($done->finished);
    }

    #[DataProvider('storageProvider')]
    public function testFailRunningByQueue(string $database): void {
        $queue = $this->createQueue($database);
        $main = $queue->enqueue('a');
        $other = $queue->enqueue('b', [], 'other');
        $queue->claimNext();
        $queue->claimNext(['other']);

        self::assertSame(1, $queue->failRunning('The worker died.'));

        self::assertSame(JobStatus::FAILED, $queue->get($main->id)->status);
        self::assertSame('The worker died.', $queue->get($main->id)->error);
        self::assertSame(JobStatus::RUNNING, $queue->get($other->id)->status);
        self::assertSame(0, $queue->failRunning('Again'));
    }

    #[DataProvider('storageProvider')]
    public function testRemovePayloadKeys(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a', ['password' => 'secret', 'name' => 'x']);

        $queue->removePayloadKeys($job->id, ['password', 'missing']);

        self::assertSame(['name' => 'x'], $queue->get($job->id)->payload);
    }

    #[DataProvider('storageProvider')]
    public function testListAndDelete(string $database): void {
        $queue = $this->createQueue($database);
        $first = $queue->enqueue('a');
        $second = $queue->enqueue('b', [], 'other');
        $third = $queue->enqueue('c');

        self::assertSame([$third->id, $second->id, $first->id], array_column($queue->list(), 'id'));
        self::assertSame([$third->id, $first->id], array_column($queue->list(10, ['main']), 'id'));
        self::assertSame([$third->id], array_column($queue->list(1), 'id'));

        $queue->delete($second->id);

        self::assertNull($queue->get($second->id));
        self::assertSame([], $queue->list(10, ['other']));
    }

    /**
     * Another table name and prefix: the schema and the queries use them.
     */
    #[DataProvider('storageProvider')]
    public function testCustomTable(string $database): void {
        $storage = $this->createStorage($database, 'tasks', 'x_');
        $queue = new JobQueue($storage, 'default');

        $job = $queue->enqueue('a');

        self::assertSame('default', $job->queue);
        self::assertSame(
            1,
            (int)$storage->getDriver()->getFirstScalarResult('SELECT COUNT(*) FROM `x_tasks`')
        );
    }

    protected function createQueue(string $database): JobQueue {
        return new JobQueue($this->createStorage($database));
    }

    protected function createStorage(string $database, string $table = 'jobs', string $prefix = ''): IQueueStorage {
        if ($database === 'sqlite') {
            return TestStorage::sqlite($table, $prefix);
        }

        return TestStorage::mysql($table, $prefix ?: 'test_')
            ?? self::markTestSkipped('QUEUE_TEST_MYSQL_DSN is not set.');
    }
}
