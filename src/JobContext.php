<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\DataStorage\Exception\DBException;
use Runway\QueueManager\Exception\JobCancelledException;

/**
 * The link of a running job handler to the queue: the progress goes to the database (e.g. a page shows it), and the
 * cancellation comes back.
 */
class JobContext {
    protected float $lastReportTime = 0.0;

    /**
     * @param float $minReportInterval In seconds: the handlers can report the progress very often (e.g. after every
     *                                 copied file), but the database is written not more often than that.
     */
    public function __construct(
        protected IJobQueue $queue,
        public readonly int $jobId,
        protected float     $minReportInterval = 0.5
    ) {}

    /**
     * @param array<mixed> $progress Any JSON-encodable data, e.g. the stage and the done part.
     * @param bool         $force    Write it anyway, e.g. at the start of a new stage.
     *
     * @throws JobCancelledException If the job is cancelled: the handler should stop (it's checked at every write).
     * @throws DBException
     */
    public function reportProgress(array $progress, bool $force = false): void {
        $now = microtime(true);

        if (!$force && $now - $this->lastReportTime < $this->minReportInterval) {
            return;
        }

        $this->lastReportTime = $now;
        $this->queue->setProgress($this->jobId, $progress);

        if ($this->queue->isCancelRequested($this->jobId)) {
            throw new JobCancelledException("Job #$this->jobId is cancelled.");
        }
    }
}
