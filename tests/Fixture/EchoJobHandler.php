<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\JobContext;

/**
 * "echo": the result is the payload.
 */
class EchoJobHandler extends AJobHandler {
    public function getType(): string {
        return 'echo';
    }

    public function handle(JobDTO $job, JobContext $context): array {
        $context->reportProgress(['stage' => 'echo'], true);

        return $job->payload;
    }
}
