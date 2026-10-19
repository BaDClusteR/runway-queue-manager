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
use Runway\QueueManager\QueueWorker;
use Runway\QueueManager\Tests\Fixture\TestHandler;
use Runway\QueueManager\Tests\Fixture\TestStorage;
use Runway\Singleton\Container;

class QueueWorkerTest extends TestCase {
    protected JobQueue $queue;

    protected string $lockFolder;

    protected function setUp(): void {
        $this->queue = new JobQueue(TestStorage::sqlite());
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
     * @param TestHandler[] $handlers
     */
    protected function createWorker(array $handlers): QueueWorker {
        return new QueueWorker(
            $this->queue,
            new JobHandlerProvider($handlers),
            Container::getInstance()->getService(ILogger::class),
            'main',
            $this->lockFolder
        );
    }
}
