<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\E2E\Fixture;

use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\JobContext;

/**
 * "sleep": a long job of the payload "steps" of "ms" milliseconds, the progress is reported after every step. The
 * "password" of the payload is secret.
 */
class SleepJobHandler extends AJobHandler {
    public function getType(): string {
        return 'sleep';
    }

    public function getSecretPayloadKeys(): array {
        return ['password'];
    }

    public function handle(JobDTO $job, JobContext $context): array {
        $steps = is_int($job->payload['steps'] ?? null) ? $job->payload['steps'] : 1;
        $ms = is_int($job->payload['ms'] ?? null) ? $job->payload['ms'] : 100;

        for ($step = 1; $step <= $steps; $step++) {
            usleep($ms * 1000);
            $context->reportProgress(['step' => $step], true);
        }

        return ['steps' => $steps];
    }
}
