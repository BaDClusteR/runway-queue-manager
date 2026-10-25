<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Runway\DataStorage\Exception\DBException;
use Runway\Logger\ILogger;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\Exception\InvalidQueueNameException;
use Runway\QueueManager\Handler\JobHandlerProvider;
use Runway\QueueManager\JobContext;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\QueuePruner;
use Runway\QueueManager\QueueWorker;
use Runway\QueueManager\Storage\IQueueSchema;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Tests\Fixture\RetryingTestHandler;
use Runway\QueueManager\Tests\Fixture\TestHandler;
use Runway\QueueManager\Tests\Fixture\TestLogger;
use Runway\QueueManager\Tests\Fixture\TestStorage;
use Runway\QueueManager\Tests\Support\JobAssertions;
use Runway\Singleton\Container;

class QueueWorkerTest extends TestCase {
    use JobAssertions;

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
        self::assertSame(['sum' => 3], self::found($this->queue->get($first->id))->result);
        self::assertSame(['sum' => 9], self::found($this->queue->get($second->id))->result);
        self::assertSame(JobStatus::QUEUED, self::found($this->queue->get($other->id))->status);
        self::assertContains("Job #$first->id (sum, main): done.", $events);

        self::assertSame(1, $this->createWorker([$handler])->run(queues: ['other']));
        self::assertSame(JobStatus::DONE, self::found($this->queue->get($other->id))->status);
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

        self::assertSame(JobStatus::FAILED, self::found($this->queue->get($unknown->id))->status);
        self::assertSame('Unknown job type "unknown".', self::found($this->queue->get($unknown->id))->error);
        self::assertSame(JobStatus::FAILED, self::found($this->queue->get($failed->id))->status);
        self::assertSame('Disk is full', self::found($this->queue->get($failed->id))->error);
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

        $cancelled = self::found($this->queue->get($job->id));
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
        self::assertSame(['hasPassword' => true], self::found($this->queue->get($job->id))->result);
        self::assertSame(['name' => 'x'], self::found($this->queue->get($job->id))->payload);
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
        self::assertIsResource($lock);
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

        self::assertSame(JobStatus::FAILED, self::found($this->queue->get($stale->id))->status);
        self::assertSame(
            'The worker stopped while running the job.',
            self::found($this->queue->get($stale->id))->error
        );
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

        $done = self::found($this->queue->get($job->id));
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

        self::assertSame(JobStatus::FAILED, self::found($this->queue->get($first->id))->status);
        self::assertSame(2, self::found($this->queue->get($first->id))->attempts);
        self::assertSame('Service is down', self::found($this->queue->get($first->id))->error);

        $errors = array_values(
            array_filter($logger->records, static fn(array $record): bool => $record[0] === 'error')
        );
        self::assertCount(2, $errors);
        self::assertSame('Job of type "notify" failed: Service is down', $errors[0][1]);
        self::assertSame($errors[0][1], $errors[1][1]);
        self::assertSame(
            [$first->id, $second->id],
            array_map(static fn(array $record): mixed => $record[2]['jobId'], $errors)
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

        $queued = self::found($this->queue->get($job->id));
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

        self::assertSame(['hasToken' => true], self::found($this->queue->get($job->id))->result);
        self::assertSame(['text' => 'Hi'], self::found($this->queue->get($job->id))->payload);
    }

    /**
     * The jobs of a handler that does not retry fail at once.
     */
    public function testNotRetryingHandlerFailsAtOnce(): void {
        $handler = new TestHandler('a', static fn(): array => throw new RuntimeException('Disk is full'));
        $job = $this->queue->enqueue('a');
        $logger = new TestLogger();

        $this->createWorker([$handler], $logger)->run();

        self::assertSame(JobStatus::FAILED, self::found($this->queue->get($job->id))->status);
        self::assertSame(1, self::found($this->queue->get($job->id))->attempts);
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
     * The trace of a failure is logged without the argument values: they can be secrets of the payload.
     */
    public function testLoggedTraceHasNoArguments(): void {
        $connect = static fn(string $password): array => throw new RuntimeException('Access denied');
        $handler = new TestHandler(
            'export',
            static fn(JobDTO $job): array => $connect(
                is_string($job->payload['password']) ? $job->payload['password'] : ''
            ),
            ['password']
        );
        $job = $this->queue->enqueue('export', ['password' => 'TopSecret12345']);
        $logger = new TestLogger();

        $this->createWorker([$handler], $logger)->run();

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame('error', $level);
        self::assertSame('Job of type "export" failed: Access denied', $message);
        self::assertSame(['jobId', 'attempt', 'exception', 'trace'], array_keys($context));
        self::assertSame($job->id, $context['jobId']);
        self::assertSame(1, $context['attempt']);
        self::assertSame(RuntimeException::class, $context['exception']);
        self::assertIsString($context['trace']);
        self::assertStringNotContainsString('TopSecret', $context['trace']);
        self::assertMatchesRegularExpression(
            '/^#0 \S+QueueWorkerTest\.php\(\d+\): .*\{closure.*\}\(\)$/m',
            $context['trace']
        );
        self::assertStringContainsString(
            ': Runway\QueueManager\Tests\Fixture\TestHandler->handle()',
            $context['trace']
        );
        $lines = explode("\n", $context['trace']);

        foreach ($lines as $index => $line) {
            // Every frame is a line, numbered; the last one is the entry point.
            self::assertMatchesRegularExpression(
                ($index === count($lines) - 1) ? "/^#$index \\{main\\}\$/" : "/^#$index \\S.*: \\S+\\(\\)\$/",
                $line
            );
        }
    }

    public function testInternalFunctionInTrace(): void {
        $handler = new TestHandler('a', static fn(): array => array_map(
            static fn(): never => throw new RuntimeException('Error'),
            [1]
        ));
        $this->queue->enqueue('a');
        $logger = new TestLogger();

        $this->createWorker([$handler], $logger)->run();

        self::assertIsString($logger->records[0][2]['trace']);
        self::assertMatchesRegularExpression(
            '/^#0 \[internal function\]: .*\{closure.*\}\(\)\n#1 \S+QueueWorkerTest\.php\(\d+\): array_map\(\)\n/',
            $logger->records[0][2]['trace']
        );
    }

    public function testEvents(): void {
        $failures = 1;
        $handler = new RetryingTestHandler('notify', static function () use (&$failures): array {
            if ($failures-- > 0) {
                throw new RuntimeException('Down');
            }

            return [];
        }, [0]);
        $stale = $this->queue->enqueue('a');
        $this->queue->claimNext();
        $unknown = $this->queue->enqueue('unknown', [], 'main');
        $job = $this->queue->enqueue('notify');
        $events = [];

        $this->createWorker([$handler])->run(static function (string $message) use (&$events): void {
            $events[] = $message;
        });

        self::assertSame(
            [
                '1 job(s) of a stopped worker marked failed.',
                "Job #$unknown->id (unknown, main): failed, unknown type.",
                "Job #$job->id (notify, main): started.",
                "Job #$job->id (notify, main): failed, retry in 0s: Down",
                "Job #$job->id (notify, main): started, attempt 2.",
                "Job #$job->id (notify, main): done.",
            ],
            $events
        );
        self::assertSame(JobStatus::FAILED, self::found($this->queue->get($stale->id))->status);
    }

    public function testEventsOfFailureAndCancellation(): void {
        $queue = $this->queue;
        $failing = new TestHandler('failing', static fn(): array => throw new RuntimeException('Disk is full'));
        $cancelled = new TestHandler('long', static function (JobDTO $job, JobContext $context) use ($queue): array {
            $queue->requestCancel($job->id);
            $context->reportProgress([], true);

            return [];
        });
        $failed = $this->queue->enqueue('failing');
        $long = $this->queue->enqueue('long');
        $events = [];

        $this->createWorker([$failing, $cancelled])->run(static function (string $message) use (&$events): void {
            $events[] = $message;
        });

        self::assertSame(
            [
                "Job #$failed->id (failing, main): started.",
                "Job #$failed->id (failing, main): failed: Disk is full",
                "Job #$long->id (long, main): started.",
                "Job #$long->id (long, main): cancelled.",
            ],
            $events
        );
    }

    public function testNoStaleJobsNoEvent(): void {
        $events = [];

        self::assertSame(0, $this->createWorker([])->run(static function (string $message) use (&$events): void {
            $events[] = $message;
        }));
        self::assertSame([], $events);
    }

    /**
     * The table of an older version of the library is upgraded when the worker starts.
     */
    public function testSchemaIsInstalledOnStart(): void {
        $schema = new class implements IQueueSchema {
            public int $installs = 0;

            public function install(): void {
                $this->installs++;
            }

            public function getStatements(): array {
                return [];
            }
        };
        $worker = new QueueWorker(
            $this->queue,
            new JobHandlerProvider([]),
            new TestLogger(),
            'main',
            $this->lockFolder,
            $schema
        );

        $worker->run();
        self::assertSame(1, $schema->installs);

        // The worker has made the folder.
        $lock = fopen("$this->lockFolder/main.lock", 'c');
        self::assertIsResource($lock);
        flock($lock, LOCK_EX);

        try {
            // Another worker runs: it has upgraded the table.
            self::assertNull($worker->run());
            self::assertSame(1, $schema->installs);
        } finally {
            fclose($lock);
        }
    }

    public function testDefaultQueueAndLockFolder(): void {
        $folder = PROJECT_ROOT . '/var/queue';
        $handler = new TestHandler('a', static fn(): array => []);
        $media = $this->queue->enqueue('a', [], 'media');
        $main = $this->queue->enqueue('a');
        $worker = new QueueWorker($this->queue, new JobHandlerProvider([$handler]), new TestLogger(), 'media');

        self::assertSame(1, $worker->run());

        self::assertSame(JobStatus::DONE, self::found($this->queue->get($media->id))->status);
        self::assertSame(JobStatus::QUEUED, self::found($this->queue->get($main->id))->status);
        self::assertFileExists("$folder/media.lock");
        self::assertSame(
            1,
            new QueueWorker($this->queue, new JobHandlerProvider([$handler]), new TestLogger(), lockFolder: '')->run()
        );
        self::assertFileExists("$folder/main.lock");
    }

    /**
     * The locks are released when the worker is done, and when a job throws out of it (e.g. the database is gone).
     */
    public function testLocksAreReleased(): void {
        $worker = $this->createWorker([new TestHandler('a', static fn(): array => [])]);
        $worker->run(queues: ['one', 'two']);

        self::assertFalse($worker->isRunning(['one']));
        self::assertFalse($worker->isRunning(['two']));

        $this->queue->enqueue('a', [], 'one');
        $this->storage->getDriver()->execute("DROP TABLE {$this->storage->getTable()}");

        try {
            $worker->run(queues: ['one']);
            self::fail('The table is gone.');
        } catch (DBException) {
            self::assertFalse($worker->isRunning(['one']));
        }
    }

    public function testLockFolderCannotBeCreated(): void {
        $file = tempnam(sys_get_temp_dir(), 'queue');
        self::assertIsString($file);

        try {
            $worker = new QueueWorker(
                $this->queue,
                new JobHandlerProvider([]),
                new TestLogger(),
                'main',
                "$file/locks"
            );

            $this->expectException(\Runway\Exception\RuntimeException::class);
            $this->expectExceptionMessage("Cannot create folder $file/locks.");

            @$worker->isRunning();
        } finally {
            unlink($file);
        }
    }

    public function testLockFileCannotBeOpened(): void {
        $worker = $this->createWorker([]);
        mkdir("$this->lockFolder/two.lock", 0775, true);

        try {
            @$worker->run(queues: ['one', 'two']);
            self::fail('The lock file is a folder.');
        } catch (\Runway\Exception\RuntimeException $e) {
            self::assertSame("Cannot open the lock file $this->lockFolder/two.lock.", $e->getMessage());
            // The lock taken before is released.
            self::assertFalse($worker->isRunning(['one']));
        } finally {
            rmdir("$this->lockFolder/two.lock");
        }
    }

    public function testInvalidQueue(): void {
        $this->expectException(InvalidQueueNameException::class);

        $this->createWorker([])->run(queues: ['../main']);
    }

    /**
     * A cancelled job of a retrying handler does not need its secrets any more.
     */
    public function testCancelledRetryingJobSecretsAreRemoved(): void {
        $queue = $this->queue;
        $cancel = static function (JobDTO $job, JobContext $context) use ($queue): array {
            $queue->requestCancel($job->id);
            $context->reportProgress([], true);

            return [];
        };
        $handler = new RetryingTestHandler('notify', $cancel, [60], ['token']);
        $job = $this->queue->enqueue('notify', ['token' => 't', 'channel' => 'c']);

        $this->createWorker([$handler])->run();

        self::assertSame(JobStatus::CANCELLED, self::found($this->queue->get($job->id))->status);
        self::assertSame(['channel' => 'c'], self::found($this->queue->get($job->id))->payload);
    }

    /**
     * The delays are taken in order, whatever the keys; a negative one is no delay.
     */
    public function testRetryDelays(): void {
        $handler = new RetryingTestHandler(
            'notify',
            static fn(): array => throw new RuntimeException('Down'),
            ['first' => -10, 'second' => 3600]
        );
        $job = $this->queue->enqueue('notify');

        self::assertSame(2, $this->createWorker([$handler])->run());

        $retried = self::found($this->queue->get($job->id));
        self::assertSame(JobStatus::QUEUED, $retried->status);
        self::assertSame(2, $retried->attempts);
        self::assertGreaterThanOrEqual(time() + 3590, $retried->available);
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
