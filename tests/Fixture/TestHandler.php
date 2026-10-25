<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Closure;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\JobContext;

/**
 * Runs the closure as the job and remembers the jobs it got.
 */
final class TestHandler extends AJobHandler {
    /** @var JobDTO[] */
    public array $handled = [];

    /** @var JobDTO[] The jobs cleaned up (see IJobHandler::cleanUp()). */
    public array $cleanedUp = [];

    /**
     * @param Closure(JobDTO, JobContext): array<mixed> $handle
     * @param string[]                                   $secretKeys
     */
    public function __construct(
        protected string  $type,
        protected Closure $handle,
        protected array   $secretKeys = []
    ) {}

    public function getType(): string {
        return $this->type;
    }

    public function getSecretPayloadKeys(): array {
        return $this->secretKeys;
    }

    public function handle(JobDTO $job, JobContext $context): array {
        $this->handled[] = $job;

        return ($this->handle)($job, $context);
    }

    public function cleanUp(JobDTO $job): void {
        $this->cleanedUp[] = $job;
    }
}
