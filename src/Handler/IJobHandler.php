<?php

declare(strict_types=1);

namespace Runway\QueueManager\Handler;

use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Exception\JobCancelledException;
use Runway\QueueManager\JobContext;
use Throwable;

/**
 * Runs the jobs of one type in the worker (see IQueueWorker). The handlers are the services with the
 * "queue.job_handler" tag (see JobHandlerProvider). AJobHandler has the defaults of the optional methods.
 */
interface IJobHandler {
    /**
     * The job type, e.g. "export": see IJobQueue::enqueue().
     */
    public function getType(): string;

    /**
     * The payload keys removed from the database when the job starts, e.g. a password: the job has them in memory.
     *
     * @return string[]
     */
    public function getSecretPayloadKeys(): array;

    /**
     * @param JobContext $context To report the progress (and to find out that the job is cancelled).
     *
     * @return array The result to store (JSON-encodable), e.g. the details of the made file.
     *
     * @throws JobCancelledException If the job is cancelled: the handler stops.
     * @throws Throwable The job fails with its message.
     */
    public function handle(JobDTO $job, JobContext $context): array;

    /**
     * Deletes what the job has made (e.g. a file) when the job itself is deleted: the queue does not call it, the
     * application does when it deletes a job.
     */
    public function cleanUp(JobDTO $job): void;
}
