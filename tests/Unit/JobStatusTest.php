<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Enum\JobStatus;

class JobStatusTest extends TestCase {
    public function testIsFinished(): void {
        self::assertFalse(JobStatus::QUEUED->isFinished());
        self::assertFalse(JobStatus::RUNNING->isFinished());
        self::assertTrue(JobStatus::DONE->isFinished());
        self::assertTrue(JobStatus::FAILED->isFinished());
        self::assertTrue(JobStatus::CANCELLED->isFinished());
    }

    public function testValuesAreTheOnesOfTheTable(): void {
        self::assertSame(
            ['queued', 'running', 'done', 'failed', 'cancelled'],
            array_map(static fn(JobStatus $status): string => $status->value, JobStatus::cases())
        );
    }
}
