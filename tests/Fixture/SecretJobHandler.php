<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\JobContext;

/**
 * "secret": the "password" of the payload is secret, the result tells whether the handler has got it.
 */
class SecretJobHandler extends AJobHandler {
    public function getType(): string {
        return 'secret';
    }

    public function getSecretPayloadKeys(): array {
        return ['password'];
    }

    public function handle(JobDTO $job, JobContext $context): array {
        return ['hasPassword' => isset($job->payload['password'])];
    }
}
