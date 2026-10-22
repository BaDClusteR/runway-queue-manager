<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Closure;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Handler\AJobHandler;
use Runway\QueueManager\Handler\IRetryingJobHandler;
use Runway\QueueManager\JobContext;

/**
 * Runs the closure as the job, its failures are retried after the delays.
 */
final class RetryingTestHandler extends AJobHandler implements IRetryingJobHandler {
    /** @var JobDTO[] */
    public array $handled = [];

    /**
     * @param Closure(JobDTO, JobContext): array $handle
     * @param int[]                              $delays
     * @param string[]                           $secretKeys
     */
    public function __construct(
        protected string  $type,
        protected Closure $handle,
        protected array   $delays,
        protected array   $secretKeys = []
    ) {}

    public function getType(): string {
        return $this->type;
    }

    public function getSecretPayloadKeys(): array {
        return $this->secretKeys;
    }

    public function getRetryDelays(): array {
        return $this->delays;
    }

    public function handle(JobDTO $job, JobContext $context): array {
        $this->handled[] = $job;

        return ($this->handle)($job, $context);
    }
}
