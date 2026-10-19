<?php

declare(strict_types=1);

namespace Runway\QueueManager\Handler;

use Runway\QueueManager\DTO\JobDTO;

/**
 * A handler without secret payload keys and without anything to clean up.
 */
abstract class AJobHandler implements IJobHandler {
    public function getSecretPayloadKeys(): array {
        return [];
    }

    public function cleanUp(JobDTO $job): void {}
}
