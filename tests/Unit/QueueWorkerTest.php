<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Runway\Logger\ILogger;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\Handler\JobHandlerProvider;
use Runway\QueueManager\JobContext;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\QueuePruner;
use Runway\QueueManager\QueueWorker;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Tests\Fixture\RetryingTestHandler;
use Runway\QueueManager\Tests\Fixture\TestHandler;
use Runway\QueueManager\Tests\Fixture\TestLogger;
use Runway\QueueManager\Tests\Fixture\TestStorage;
use Runway\Singleton\Container;

class QueueWorkerTest extends TestCase {
    protected JobQueue $queue;

    protected IQueueStorage $storage;

    protected string $lockFolder;

    protected function setUp(): void {
        $this->storage = TestStorage::sqlite();
        $this->queue = new JobQueue($this->storage);
        $this->lockFolder = sys_get_temp_dir() . '/runway-queue-manager-tests/locks-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        array_map(unlink(...), glob("$this->lockFolder/*.lock") ?: []);

        if (is_dir($this->lockFolder)) {
            rmdir($this->lockFolder);
        }
    }

    public function testRunsJobsOfItsQueue(): void {
        $handler = new TestHandler('sum', static fn(JobDTO $job): array => ['sum' => array_sum($job->payload)]);
        $first = $this->queue->enqueue('sum', [1, 2]);
        $other = $this->queue->enqueue('sum', [3], 'other');
        $second = $this->queue->enqueue('sum', [4, 5]);
        $events = [];

        $count = $this->createWorker([$handler])->run(function (string $message) use (&$events) {
            $events[] = $message;
        });

        self::assertSame(2, $count);
        self::assertSame(['sum' => 3], $this->queue->get($first->id)->result);
        self::assertSame(['sum' => 9], $this->queue->get($second->id)->result);
        self::assertSame(JobStatus::QUEUED, $this->queue->get($other->id)->status);
        self::assertContains("Job #$first->id (sum, main): done.", $events);

        self::assertSame(1, $this->createWorker([$handler])->run(queues: ['other']));
        self::assertSame(JobStatus::DONE, $this->queue->get($other->id)->status);
    }

    public function testOnce(): void {
        $handler = new TestHandler('a', static fn(): array => []);
        $this->queue->enqueue('a');
        $this->queue->enqueue('a');

        self::assertSame(1, $this->createWorker([$handler])->run(once: true));
        $statuses = array_map(static fn(JobDTO $job): JobStatus => $job->status, $this->queue->list());
        self::assertSame([JobStatus::QUEUED, JobStatus::DONE], $statuses);
    }

    public function testFailures(): void {
        $failing = new TestHandler('failing', static fn(): array => throw new RuntimeException('Disk is full'));
        $unknown = $this->queue->enqueue('unknown');
        $failed = $this->queue->enqueue('failing');

        $this->createWorker([$failing])->run();

        self::assertSame(JobStatus::FAILED, $this->queue->get($unknown->id)->status);
        self::assertSame('Unknown job type "unknown".', $this->queue->get($unknown->id)->error);
        self::assertSame(JobStatus::FAILED, $this->queue->get($failed->id)->status);
        self::assertSame('Disk is full', $this->queue->get($failed->id)->error);
    }

    /**
     * The handler gets the cancellation at its next progress report and stops.
     */
    public function testCancellation(): void {
        $queue = $this->queue;
        $handler = new TestHandler('long', static function (JobDTO $job, JobContext $context) use ($queue): array {
            $context->reportProgress(['step' => 1]);
            $queue->requestCancel($job->id);
            $context->reportProgress(['step' => 2], force: true);

            return ['never' => true];
        });
        $job = $this->queue->enqueue('long');

        $this->createWorker([$handler])->run();

        $cancelled = $this->queue->get($job->id);
        self::assertSame(JobStatus::CANCELLED, $cancelled->status);
        self::assertSame(['step' => 2], $cancelled->progress);
        self::assertNull($cancelled->result);
    }

    public function testSecretPayloadKeysAreRemoved(): void {
        $handler = new TestHandler(
            'export',
            static fn(JobDTO $job): array => ['hasPassword' => isset($job->payload['password'])],
            ['password']
        );
        $job = $this->queue->enqueue('export', ['password' => 'secret', 'name' => 'x']);

        $this->createWorker([$handler])->run();

        // The handler has the key in memory, the database does not.
        self::assertSame(['hasPassword' => true], $this->queue->get($job->id)->result);
        self::assertSame(['name' => 'x'], $this->queue->get($job->id)->payload);
    }

    /**
     * A second worker of a queue exits at once, the workers of other queues run.
     */
    public function testOneWorkerPerQueue(): void {
        $handler = new TestHandler('a', static fn(): array => []);
        $this->queue->enqueue('a');
        $this->queue->enqueue('a', [], 'other');
        $worker = $this->createWorker([$handler]);
        mkdir($this->lockFolder, 0775, true);
        $lock = fopen("$this->lockFolder/main.lock", 'c');
        flock($lock, LOCK_EX);

        try {
            self::assertTrue($worker->isRunning());
            self::assertTrue($worker->isRunning(['main', 'other']));
            self::assertFalse($worker->isRunning(['other']));
            self::assertNull($worker->run());
            self::assertNull($worker->run(queues: ['other', 'main']));
            self::assertSame(1, $worker->run(queues: ['other']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertFalse($worker->isRunning());
        self::assertSame(1, $worker->run());
    }

    /**
     * The jobs left running by a dead worker of the queue fail when the next worker starts.
     */
    public function testJobsOfDeadWorkerFail(): void {
        $stale = $this->queue->enqueue('a');
        $this->queue->claimNext();

        $this->createWorker([new TestHandler('a', static fn(): array => [])])->run();

        self::assertSame(JobStatus::FAILED, $this->queue->get($stale->id)->status);
        self::assertSame('The worker stopped while running the job.', $this->queue->get($stale->id)->error);
    }

    /**
     * Without a delay, the same worker runs the retry; the failed attempts are info, the job is done in the end.
     */
    public function testRetriedJobIsDone(): void {
        $failures = 2;
        $handler = new RetryingTestHandler('notify', static function () use (&$failures): array {
            if ($failures-- > 0) {
                throw new RuntimeException('Service is down');
            }

            return ['sent' => true];
        }, [0, 0, 0]);
        $job = $this->queue->enqueue('notify');
        $logger = new TestLogger();

        // Every attempt is a run.
        self::assertSame(3, $this->createWorker([$handler], $logger)->run());

        $done = $this->queue->get($job->id);
        self::assertSame(JobStatus::DONE, $done->status);
        self::assertSame(3, $done->attempts);
        self::assertSame(['sent' => true], $done->result);
        self::assertSame([1, 2, 3], array_map(static fn(JobDTO $job): int => $job->attempts, $handler->handled));
        self::assertSame(['info', 'info'], array_column($logger->records, 0));
    }

    /**
     * When the delays are over, the job fails: one error record, the same text for every job (the ID is in the
     * context).
     */
    public function testRetriesAreOver(): void {
        $handler = new RetryingTestHandler(
            'notify',
            static fn(): array => throw new RuntimeException('Service is down'),
            [0]
        );
        $first = $this->queue->enqueue('notify');
        $second = $this->queue->enqueue('notify');
        $logger = new TestLogger();

        $this->createWorker([$handler], $logger)->run();

        self::assertSame(JobStatus::FAILED, $this->queue->get($first->id)->status);
        self::assertSame(2, $this->queue->get($first->id)->attempts);
        self::assertSame('Service is down', $this->queue->get($first->id)->error);

        $errors = array_values(
            array_filter($logger->records, static fn(array $record): bool => $record[0] === 'error')
        );
        self::assertCount(2, $errors);
        self::assertSame('Job of type "notify" failed: Service is down', $errors[0][1]);
        self::assertSame($errors[0][1], $errors[1][1]);
        self::assertSame(
            [$first->id, $second->id],
            array_map(static fn(array $record): int => $record[2]['jobId'], $errors)
        );
    }

    /**
     * A delayed retry is left to the next worker; the secrets stay for it.
     */
    public function testDelayedRetryKeepsSecrets(): void {
        $handler = new RetryingTestHandler(
            'notify',
            static fn(): array => throw new RuntimeException('Service is down'),
            [3600],
            ['token']
        );
        $job = $this->queue->enqueue('notify', ['token' => 'secret', 'text' => 'Hi']);

        self::assertSame(1, $this->createWorker([$handler], new TestLogger())->run());

        $queued = $this->queue->get($job->id);
        self::assertSame(JobStatus::QUEUED, $queued->status);
        self::assertSame('Service is down', $queued->error);
        self::assertSame(['token' => 'secret', 'text' => 'Hi'], $queued->payload);
        self::assertSame(0, $this->createWorker([$handler], new TestLogger())->run());
    }

    public function testRetryingJobSecretsAreRemovedWhenDone(): void {
        $handler = new RetryingTestHandler(
            'notify',
            static fn(JobDTO $job): array => ['hasToken' => isset($job->payload['token'])],
            [60],
            ['token']
        );
        $job = $this->queue->enqueue('notify', ['token' => 'secret', 'text' => 'Hi']);

        $this->createWorker([$handler])->run();

        self::assertSame(['hasToken' => true], $this->queue->get($job->id)->result);
        self::assertSame(['text' => 'Hi'], $this->queue->get($job->id)->payload);
    }

    /**
     * The jobs of a handler that does not retry fail at once.
     */
    public function testNotRetryingHandlerFailsAtOnce(): void {
        $handler = new TestHandler('a', static fn(): array => throw new RuntimeException('Disk is full'));
        $job = $this->queue->enqueue('a');
        $logger = new TestLogger();

        $this->createWorker([$handler], $logger)->run();

        self::assertSame(JobStatus::FAILED, $this->queue->get($job->id)->status);
        self::assertSame(1, $this->queue->get($job->id)->attempts);
        self::assertSame([['error', 'Job of type "a" failed: Disk is full']], array_map(
            static fn(array $record): array => [$record[0], $record[1]],
            $logger->records
        ));
    }

    /**
     * The old finished jobs are deleted, with what they have made; the others are kept.
     */
    public function testPrune(): void {
        $handler = new TestHandler('a', static fn(): array => []);
        $failing = new TestHandler('failing', static fn(): array => throw new RuntimeException('Error'));
        $old = $this->queue->enqueue('a');
        $oldFailed = $this->queue->enqueue('failing');
        $oldUnknown = $this->queue->enqueue('removed-type');
        $this->createWorker([$handler, $failing], new TestLogger())->run();
        $recent = $this->queue->enqueue('a');
        $this->createWorker([$handler])->run();
        $queued = $this->queue->enqueue('a');
        $this->setFinished([$old->id, $oldFailed->id, $oldUnknown->id], time() - 86400);

        $pruner = new QueuePruner($this->queue, new JobHandlerProvider([$handler, $failing]), new TestLogger());

        self::assertSame(3, $pruner->prune(time() - 3600));
        self::assertNull($this->queue->get($old->id));
        self::assertNull($this->queue->get($oldFailed->id));
        self::assertNull($this->queue->get($oldUnknown->id));
        self::assertNotNull($this->queue->get($recent->id));
        self::assertNotNull($this->queue->get($queued->id));
        self::assertSame([$old->id], array_map(static fn(JobDTO $job): int => $job->id, $handler->cleanedUp));
        self::assertSame([$oldFailed->id], array_map(static fn(JobDTO $job): int => $job->id, $failing->cleanedUp));
        self::assertSame(0, $pruner->prune(time() - 3600));
    }

    /**
     * @param int[] $jobIds
     */
    protected function setFinished(array $jobIds, int $finished): void {
        $this->storage->getDriver()->execute(
            "UPDATE {$this->storage->getTable()} SET finished = :finished WHERE id IN (" . implode(', ', $jobIds) . ')',
            ['finished' => $finished]
        );
    }

    /**
     * @param array<TestHandler|RetryingTestHandler> $handlers
     */
    protected function createWorker(array $handlers, ?ILogger $logger = null): QueueWorker {
        return new QueueWorker(
            $this->queue,
            new JobHandlerProvider($handlers),
            $logger ?? Container::getInstance()->getService(ILogger::class),
            'main',
            $this->lockFolder
        );
    }
}
