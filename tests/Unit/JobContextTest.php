<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Exception\JobCancelledException;
use Runway\QueueManager\JobContext;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\Tests\Fixture\TestStorage;
use Runway\QueueManager\Tests\Support\JobAssertions;

class JobContextTest extends TestCase {
    use JobAssertions;

    protected JobQueue $queue;

    protected int $jobId;

    protected function setUp(): void {
        $this->queue = new JobQueue(TestStorage::sqlite());
        $this->jobId = $this->queue->enqueue('a')->id;
        $this->queue->claimNext();
    }

    public function testFirstReportIsWritten(): void {
        $context = new JobContext($this->queue, $this->jobId, 3600);

        $context->reportProgress(['done' => 1]);

        self::assertSame($this->jobId, $context->jobId);
        self::assertSame(['done' => 1], $this->getProgress());
    }

    public function testReportsAreThrottled(): void {
        $context = new JobContext($this->queue, $this->jobId, 3600);

        $context->reportProgress(['done' => 1]);
        $context->reportProgress(['done' => 2]);
        self::assertSame(['done' => 1], $this->getProgress());

        $context->reportProgress(['done' => 3], true);
        self::assertSame(['done' => 3], $this->getProgress());

        // A forced report starts the interval again.
        $context->reportProgress(['done' => 4]);
        self::assertSame(['done' => 3], $this->getProgress());
    }

    public function testEveryReportIsWrittenAfterTheInterval(): void {
        $context = new JobContext($this->queue, $this->jobId, 0.01);

        $context->reportProgress(['done' => 1]);
        usleep(20_000);
        $context->reportProgress(['done' => 2]);

        self::assertSame(['done' => 2], $this->getProgress());
    }

    public function testDefaultInterval(): void {
        $context = new JobContext($this->queue, $this->jobId);

        $context->reportProgress(['done' => 1]);
        $context->reportProgress(['done' => 2]);
        self::assertSame(['done' => 1], $this->getProgress());

        usleep(510_000);
        $context->reportProgress(['done' => 3]);
        self::assertSame(['done' => 3], $this->getProgress());
    }

    public function testCancellationIsCheckedAtEveryWrite(): void {
        $context = new JobContext($this->queue, $this->jobId, 3600);
        $context->reportProgress(['done' => 1]);
        $this->queue->requestCancel($this->jobId);

        // Not written: not checked.
        $context->reportProgress(['done' => 2]);

        $this->expectException(JobCancelledException::class);
        $this->expectExceptionMessage("Job #$this->jobId is cancelled.");

        try {
            $context->reportProgress(['done' => 3], true);
        } finally {
            // The progress is written before the check.
            self::assertSame(['done' => 3], $this->getProgress());
        }
    }

    /**
     * Read from the database every time.
     *
     * @return array<mixed>|null
     *
     * @phpstan-impure
     */
    protected function getProgress(): ?array {
        return self::found($this->queue->get($this->jobId))->progress;
    }
}
