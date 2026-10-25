<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Support;

use Runway\QueueManager\DTO\JobDTO;

trait JobAssertions {
    /**
     * The job must be there (e.g. IJobQueue::get() of an added job).
     */
    protected static function found(?JobDTO $job): JobDTO {
        self::assertNotNull($job);

        return $job;
    }
}
