<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\E2E\Fixture;

use Runway\QueueManager\Handler\IRetryingJobHandler;

/**
 * "retrying-sleep": a "sleep" job retried in an hour: it has its secrets while it runs.
 */
class RetryingSleepJobHandler extends SleepJobHandler implements IRetryingJobHandler {
    public function getType(): string {
        return 'retrying-sleep';
    }

    public function getRetryDelays(): array {
        return [3600];
    }
}
