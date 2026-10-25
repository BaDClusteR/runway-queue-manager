<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\E2E\Fixture;

use RuntimeException;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\JobContext;

/**
 * "fail": fails with the payload "message".
 */
class FailJobHandler extends AJobHandler {
    public function getType(): string {
        return 'fail';
    }

    public function handle(JobDTO $job, JobContext $context): array {
        throw new RuntimeException(is_string($job->payload['message'] ?? null) ? $job->payload['message'] : 'Failed');
    }
}
