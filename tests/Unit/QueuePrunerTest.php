<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\Handler\JobHandlerProvider;
use Runway\QueueManager\JobContext;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\QueuePruner;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Tests\Fixture\TestHandler;
use Runway\QueueManager\Tests\Fixture\TestLogger;
use Runway\QueueManager\Tests\Fixture\TestStorage;

class QueuePrunerTest extends TestCase {
    protected IQueueStorage $storage;

    protected JobQueue $queue;

    protected function setUp(): void {
        $this->storage = TestStorage::sqlite();
        $this->queue = new JobQueue($this->storage);
    }

    /**
     * The jobs are read in pages of 100: all of them are deleted anyway.
     */
    public function testManyJobs(): void {
        $handler = new TestHandler('a', static fn(): array => []);
        $driver = $this->storage->getDriver();

        for ($i = 0; $i < 250; $i++) {
            $driver->execute(
                "INSERT INTO {$this->storage->getTable()} (type, status, created, finished, updated)"
                . " VALUES ('a', 'done', 1, :finished, 1)",
                ['finished' => 1000 + $i]
            );
        }

        $pruner = new QueuePruner($this->queue, new JobHandlerProvider([$handler]), new TestLogger());

        self::assertSame(200, $pruner->prune(1200));
        self::assertCount(200, $handler->cleanedUp);
        // The jobs finished at 1200 and later are kept.
        self::assertSame(1200, $this->queue->listFinishedBefore(PHP_INT_MAX, 1)[0]->finished);
        self::assertSame(50, $pruner->prune(PHP_INT_MAX));
        self::assertSame([], $this->queue->list());
    }

    /**
     * A failed clean-up is logged: the job is deleted anyway, the next jobs are cleaned up.
     */
    public function testFailedCleanUp(): void {
        $failing = new class extends AJobHandler {
            public function getType(): string {
                return 'failing';
            }

            public function handle(JobDTO $job, JobContext $context): array {
                return [];
            }

            public function cleanUp(JobDTO $job): void {
                throw new LogicException('The file is locked');
            }
        };
        $handler = new TestHandler('a', static fn(): array => []);
        $first = $this->addFinishedJob('failing');
        $second = $this->addFinishedJob('a');
        $logger = new TestLogger();

        $pruner = new QueuePruner($this->queue, new JobHandlerProvider([$failing, $handler]), $logger);

        $count = $pruner->prune(time() + 1);

        self::assertSame(2, $count);
        self::assertSame([], $this->queue->list());
        self::assertSame([$second], array_map(static fn(JobDTO $job): int => $job->id, $handler->cleanedUp));
        self::assertSame(
            [[
                'warning',
                'The clean-up of a job of type "failing" failed: The file is locked',
                ['jobId' => $first, 'exception' => LogicException::class],
            ]],
            $logger->records
        );
    }

    public function testNothingToPrune(): void {
        $queued = $this->queue->enqueue('a');

        $pruner = new QueuePruner($this->queue, new JobHandlerProvider([]), new TestLogger());

        self::assertSame(0, $pruner->prune(time() + 1));
        self::assertNotNull($this->queue->get($queued->id));
    }

    protected function addFinishedJob(string $type): int {
        $job = $this->queue->enqueue($type);
        $this->queue->claimNext();
        $this->queue->complete($job->id, []);

        return $job->id;
    }
}
