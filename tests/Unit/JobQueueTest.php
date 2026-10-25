<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\Exception\InvalidQueueNameException;
use Runway\QueueManager\Handler\JobHandlerProvider;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Storage\QueueSchema;
use Runway\QueueManager\Tests\Fixture\TestHandler;
use Runway\QueueManager\Tests\Fixture\TestStorage;
use Runway\QueueManager\Tests\Support\JobAssertions;

/**
 * Every test runs on SQLite, and on MySQL / MariaDB if QUEUE_TEST_MYSQL_DSN is set (see TestStorage).
 */
class JobQueueTest extends TestCase {
    use JobAssertions;

    /**
     * @return array<string, array{string}>
     */
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

        $claimed = self::found($queue->claimNext());

        self::assertSame($first->id, $claimed->id);
        self::assertSame(JobStatus::RUNNING, $claimed->status);
        self::assertNotNull($claimed->started);
        self::assertSame($other->id, self::found($queue->claimNext(['other']))->id);
        self::assertSame($second->id, self::found($queue->claimNext(['main', 'other']))->id);
        self::assertNull($queue->claimNext(['main', 'other']));
    }

    #[DataProvider('storageProvider')]
    public function testCancelledJobIsNotClaimed(string $database): void {
        $queue = $this->createQueue($database);
        $cancelled = $queue->enqueue('a');
        $next = $queue->enqueue('b');

        self::assertTrue($queue->requestCancel($cancelled->id));
        self::assertSame(JobStatus::CANCELLED, self::found($queue->get($cancelled->id))->status);
        self::assertSame($next->id, self::found($queue->claimNext())->id);
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
        self::assertSame(JobStatus::CANCELLED, self::found($queue->get($job->id))->status);
        self::assertFalse($queue->requestCancel($job->id));
        self::assertFalse($queue->requestCancel(12345));
    }

    #[DataProvider('storageProvider')]
    public function testOnlyRunningJobIsFinished(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a');

        $queue->complete($job->id, ['size' => 1]);
        self::assertSame(JobStatus::QUEUED, self::found($queue->get($job->id))->status);

        $queue->claimNext();
        $queue->setProgress($job->id, ['done' => 5, 'total' => 10]);
        $queue->complete($job->id, ['size' => 1]);
        $queue->fail($job->id, 'Too late');

        $done = self::found($queue->get($job->id));
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

        self::assertSame(JobStatus::FAILED, self::found($queue->get($main->id))->status);
        self::assertSame('The worker died.', self::found($queue->get($main->id))->error);
        self::assertSame(JobStatus::RUNNING, self::found($queue->get($other->id))->status);
        self::assertSame(0, $queue->failRunning('Again'));
    }

    #[DataProvider('storageProvider')]
    public function testRemovePayloadKeys(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a', ['password' => 'secret', 'name' => 'x']);

        $queue->removePayloadKeys($job->id, ['password', 'missing']);

        self::assertSame(['name' => 'x'], self::found($queue->get($job->id))->payload);
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
     * A retried job is queued again with its error, and is not claimed before its time.
     */
    #[DataProvider('storageProvider')]
    public function testRetry(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a');
        $queue->claimNext();
        $queue->setProgress($job->id, ['step' => 1]);

        $queue->retry($job->id, 'Timeout', 3600);

        $retried = self::found($queue->get($job->id));
        self::assertSame(JobStatus::QUEUED, $retried->status);
        self::assertSame('Timeout', $retried->error);
        self::assertSame(1, $retried->attempts);
        self::assertNull($retried->progress);
        self::assertNull($retried->started);
        self::assertGreaterThanOrEqual(time() + 3590, $retried->available);
        self::assertNull($queue->claimNext());

        // Only a running job is retried.
        $queue->retry($job->id, 'Again', 0);
        self::assertSame('Timeout', self::found($queue->get($job->id))->error);

        $queue->requestCancel($job->id);
        self::assertSame(JobStatus::CANCELLED, self::found($queue->get($job->id))->status);
    }

    #[DataProvider('storageProvider')]
    public function testRetryWithoutDelayIsClaimedAgain(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a');
        self::assertSame(1, self::found($queue->claimNext())->attempts);

        $queue->retry($job->id, 'Timeout', 0);

        $claimed = self::found($queue->claimNext());
        self::assertSame($job->id, $claimed->id);
        self::assertSame(2, $claimed->attempts);
    }

    #[DataProvider('storageProvider')]
    public function testListFinishedBefore(string $database): void {
        $storage = $this->createStorage($database);
        $queue = new JobQueue($storage);
        $done = $this->finishJob($queue, 'done');
        $failed = $this->finishJob($queue, 'failed');
        $cancelled = $queue->enqueue('a');
        $queue->requestCancel($cancelled->id);
        $recent = $this->finishJob($queue, 'done');
        $queued = $queue->enqueue('a');
        $running = $queue->enqueue('a');
        $queue->claimNext();
        $queue->claimNext();
        $old = time() - 86400;
        $storage->getDriver()->execute(
            "UPDATE {$storage->getTable()} SET finished = :old WHERE id IN ($done->id, $failed->id, $cancelled->id)",
            ['old' => $old]
        );

        self::assertSame(
            [$done->id, $failed->id, $cancelled->id],
            array_column($queue->listFinishedBefore(time() - 3600), 'id')
        );
        self::assertSame([$done->id], array_column($queue->listFinishedBefore(time() - 3600, 1), 'id'));
        self::assertCount(4, $queue->listFinishedBefore(time() + 1));
        self::assertNotContains($queued->id, array_column($queue->listFinishedBefore(time() + 1), 'id'));
        self::assertNotContains($running->id, array_column($queue->listFinishedBefore(time() + 1), 'id'));
        self::assertNotContains($recent->id, array_column($queue->listFinishedBefore(time() - 3600), 'id'));
    }

    /**
     * A job cancelled before it starts will not run: its secrets are removed at once (see IJobHandler).
     */
    #[DataProvider('storageProvider')]
    public function testCancelOfQueuedJobRemovesSecrets(string $database): void {
        $queue = new JobQueue($this->createStorage($database), handlerProvider: $this->createHandlerProvider());
        $job = $queue->enqueue('secret', ['password' => 'p', 'name' => 'x']);
        $unknown = $queue->enqueue('unknown', ['password' => 'p']);

        self::assertTrue($queue->requestCancel($job->id));
        self::assertTrue($queue->requestCancel($unknown->id));

        self::assertSame(['name' => 'x'], self::found($queue->get($job->id))->payload);
        // The keys of a type without a handler are unknown.
        self::assertSame(['password' => 'p'], self::found($queue->get($unknown->id))->payload);
    }

    /**
     * The worker removes the secrets of a running job (see QueueWorker).
     */
    #[DataProvider('storageProvider')]
    public function testCancelOfRunningJobKeepsSecrets(string $database): void {
        $queue = new JobQueue($this->createStorage($database), handlerProvider: $this->createHandlerProvider());
        $job = $queue->enqueue('secret', ['password' => 'p']);
        $queue->claimNext();

        self::assertTrue($queue->requestCancel($job->id));

        self::assertSame(['password' => 'p'], self::found($queue->get($job->id))->payload);
    }

    #[DataProvider('storageProvider')]
    public function testSecretsAreKeptWithoutHandlers(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('secret', ['password' => 'p']);

        $queue->requestCancel($job->id);

        self::assertSame(['password' => 'p'], self::found($queue->get($job->id))->payload);
    }

    /**
     * The job of a retrying handler has its secrets while it runs: they are removed when its worker is gone.
     */
    #[DataProvider('storageProvider')]
    public function testFailRunningRemovesSecrets(string $database): void {
        $queue = new JobQueue($this->createStorage($database), handlerProvider: $this->createHandlerProvider());
        $first = $queue->enqueue('secret', ['password' => 'p', 'name' => 'x'], 'one');
        $second = $queue->enqueue('secret', ['password' => 'p'], 'two');
        $queued = $queue->enqueue('secret', ['password' => 'p'], 'one');
        $queue->claimNext(['one']);
        $queue->claimNext(['two']);

        self::assertSame(2, $queue->failRunning('The worker died.', ['two', 'one']));

        self::assertSame(['name' => 'x'], self::found($queue->get($first->id))->payload);
        self::assertSame([], self::found($queue->get($second->id))->payload);
        self::assertSame(JobStatus::FAILED, self::found($queue->get($second->id))->status);
        self::assertSame(['password' => 'p'], self::found($queue->get($queued->id))->payload);
        self::assertSame(JobStatus::QUEUED, self::found($queue->get($queued->id))->status);
    }

    #[DataProvider('storageProvider')]
    public function testNewJob(string $database): void {
        $queue = $this->createQueue($database);
        $before = time();

        $job = $queue->enqueue('a');

        self::assertGreaterThanOrEqual($before, $job->created);
        self::assertLessThanOrEqual(time(), $job->created);
        self::assertSame([], $job->payload);
        self::assertNull($job->result);
        self::assertNull($job->error);
        self::assertNull($job->started);
        self::assertNull($job->finished);
        self::assertSame(0, $job->attempts);
        self::assertSame(0, $job->available);
        self::assertSame($job->id + 1, $queue->enqueue('b')->id);
    }

    #[DataProvider('storageProvider')]
    public function testPayloadIsNotEncodable(string $database): void {
        $queue = $this->createQueue($database);

        try {
            $queue->enqueue('a', ['name' => "\xB1\x31"]);
            self::fail('The payload is JSON.');
        } catch (JsonException) {
            self::assertSame([], $queue->list());
        }
    }

    /**
     * The payload is always a JSON object: a list too.
     */
    #[DataProvider('storageProvider')]
    public function testPayloadEncoding(string $database): void {
        $storage = $this->createStorage($database);
        $queue = new JobQueue($storage);
        $job = $queue->enqueue('a', [1, 'a/b', 'Тест']);
        $empty = $queue->enqueue('a');

        self::assertSame(
            ['{"0":1,"1":"a/b","2":"Тест"}', '{}'],
            $storage->getDriver()->getColumn(
                "SELECT payload FROM {$storage->getTable()} WHERE id IN ($job->id, $empty->id) ORDER BY id",
                [],
                'payload'
            )
        );
        self::assertSame([1, 'a/b', 'Тест'], self::found($queue->get($job->id))->payload);
    }

    /**
     * A row changed outside the queue (e.g. by hand) is read anyway.
     */
    #[DataProvider('storageProvider')]
    public function testInvalidRow(string $database): void {
        $storage = $this->createStorage($database);
        $queue = new JobQueue($storage);
        $job = $queue->enqueue('a');
        $storage->getDriver()->execute(
            "UPDATE {$storage->getTable()} SET status = 'lost', payload = '{broken', progress = '5', result = ''"
            . ' WHERE id = :id',
            ['id' => $job->id]
        );

        $read = self::found($queue->get($job->id));

        self::assertSame(JobStatus::FAILED, $read->status);
        self::assertSame([], $read->payload);
        self::assertNull($read->progress);
        self::assertNull($read->result);
    }

    #[DataProvider('storageProvider')]
    public function testUnknownJob(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a', ['password' => 'p']);

        self::assertNull($queue->get($job->id + 1));
        self::assertFalse($queue->isCancelRequested($job->id + 1));
        self::assertFalse($queue->requestCancel($job->id + 1));
        $queue->removePayloadKeys($job->id + 1, ['password']);
        $queue->setProgress($job->id + 1, ['done' => 1]);
        $queue->delete($job->id + 1);

        self::assertCount(1, $queue->list());
    }

    #[DataProvider('storageProvider')]
    public function testRemovePayloadKeysWithoutTheKeys(string $database): void {
        $storage = $this->createStorage($database);
        $queue = new JobQueue($storage);
        $job = $queue->enqueue('a', ['name' => 'x']);
        $storage->getDriver()->execute("UPDATE {$storage->getTable()} SET updated = 1");

        $queue->removePayloadKeys($job->id, ['password']);
        $queue->removePayloadKeys($job->id, []);

        // Not written.
        self::assertEquals(
            1,
            $storage->getDriver()->getFirstScalarResult("SELECT updated FROM {$storage->getTable()}")
        );

        $queue->removePayloadKeys($job->id, ['name']);
        self::assertSame([], self::found($queue->get($job->id))->payload);
    }

    #[DataProvider('storageProvider')]
    public function testLimitsAreAtLeastOne(string $database): void {
        $queue = $this->createQueue($database);
        $this->finishJob($queue, 'done');
        $this->finishJob($queue, 'done');

        self::assertCount(1, $queue->list(0));
        self::assertCount(1, $queue->list(-5));
        self::assertCount(2, $queue->list());
        self::assertCount(1, $queue->listFinishedBefore(time() + 1, 0));
        self::assertCount(2, $queue->listFinishedBefore(time() + 1));
    }

    #[DataProvider('storageProvider')]
    public function testNoQueues(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a');

        self::assertSame([], $queue->list(10, []));
        self::assertNull($queue->claimNext([]));
        self::assertSame(0, $queue->failRunning('Error', []));
        self::assertSame(JobStatus::QUEUED, self::found($queue->get($job->id))->status);
    }

    #[DataProvider('storageProvider')]
    public function testOtherDefaultQueue(string $database): void {
        $queue = new JobQueue($this->createStorage($database), 'media');
        $job = $queue->enqueue('a');
        $main = $queue->enqueue('a', [], 'main');

        self::assertSame('media', $job->queue);
        self::assertSame($job->id, self::found($queue->claimNext())->id);
        self::assertNull($queue->claimNext());
        self::assertSame(1, $queue->failRunning('Error'));
        self::assertSame(JobStatus::QUEUED, self::found($queue->get($main->id))->status);
    }

    #[DataProvider('storageProvider')]
    public function testFinishedTimes(string $database): void {
        $queue = $this->createQueue($database);
        $job = $queue->enqueue('a');
        $queue->claimNext();
        $before = time();

        $queue->fail($job->id, 'Error');

        $failed = self::found($queue->get($job->id));
        self::assertSame(JobStatus::FAILED, $failed->status);
        self::assertGreaterThanOrEqual($before, $failed->finished);
        self::assertNotNull($failed->started);

        $cancelled = $queue->enqueue('a');
        $queue->requestCancel($cancelled->id);
        self::assertGreaterThanOrEqual($before, self::found($queue->get($cancelled->id))->finished);
    }

    /**
     * A table of an older version of the library gets the new columns.
     */
    #[DataProvider('storageProvider')]
    public function testSchemaUpgrade(string $database): void {
        $storage = $this->createStorage($database);
        $schema = new QueueSchema($storage);
        $driver = $storage->getDriver();
        $driver->execute("DROP TABLE {$storage->getTable()}");

        foreach ($schema->getStatements() as $statement) {
            $driver->execute((string)preg_replace('/^\s*(attempts|available) .*\n/m', '', $statement));
        }

        $queue = new JobQueue($storage);
        $old = $queue->enqueue('a');
        self::assertSame(0, $old->attempts);

        $schema->install();
        $schema->install();

        self::assertSame($old->id, self::found($queue->claimNext())->id);
        self::assertSame(1, self::found($queue->get($old->id))->attempts);
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
        self::assertEquals(1, $storage->getDriver()->getFirstScalarResult('SELECT COUNT(*) FROM `x_tasks`'));
    }

    protected function finishJob(JobQueue $queue, string $status): JobDTO {
        $job = $queue->enqueue('a');
        $queue->claimNext();
        ($status === 'done') ? $queue->complete($job->id, []) : $queue->fail($job->id, 'Error');

        return $job;
    }

    protected function createHandlerProvider(): JobHandlerProvider {
        return new JobHandlerProvider([new TestHandler('secret', static fn(): array => [], ['password'])]);
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
