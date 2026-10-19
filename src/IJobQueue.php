<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\DataStorage\Exception\DBException;
use Runway\Exception\RuntimeException;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Exception\InvalidQueueNameException;

/**
 * The background jobs in the database (see IQueueStorage): they are added here and run by the worker (see
 * IQueueWorker), which reports the progress and the result back. The jobs are in queues: a worker runs the jobs of its
 * queues only, the default queue (the queue_default parameter, "main") if not told otherwise.
 */
interface IJobQueue {
    /**
     * @param string      $type    The handler type, see IJobHandler::getType().
     * @param array       $payload The job parameters (JSON-encodable).
     * @param string|null $queue   The default queue if not set.
     *
     * @throws DBException
     * @throws InvalidQueueNameException
     * @throws RuntimeException
     */
    public function enqueue(string $type, array $payload = [], ?string $queue = null): JobDTO;

    /**
     * @throws DBException
     */
    public function get(int $jobId): ?JobDTO;

    /**
     * @param string[]|null $queues Only the jobs of these queues; null - of all the queues.
     *
     * @return JobDTO[] The newest first.
     *
     * @throws DBException
     * @throws InvalidQueueNameException
     */
    public function list(int $limit = 50, ?array $queues = null): array;

    /**
     * Marks the oldest queued job of the queues running and returns it.
     *
     * @param string[]|null $queues The default queue if not set.
     *
     * @throws DBException
     * @throws InvalidQueueNameException
     */
    public function claimNext(?array $queues = null): ?JobDTO;

    /**
     * @param string[] $keys
     *
     * @throws DBException
     */
    public function removePayloadKeys(int $jobId, array $keys): void;

    /**
     * @throws DBException
     */
    public function setProgress(int $jobId, array $progress): void;

    /**
     * @throws DBException
     */
    public function complete(int $jobId, array $result): void;

    /**
     * @throws DBException
     */
    public function fail(int $jobId, string $error): void;

    /**
     * Marks a running job cancelled (when its handler has stopped).
     *
     * @throws DBException
     */
    public function markCancelled(int $jobId): void;

    /**
     * A queued job is cancelled at once, a running one is cancelled by its handler (see JobContext).
     *
     * @return bool False if the job is already finished.
     *
     * @throws DBException
     */
    public function requestCancel(int $jobId): bool;

    /**
     * @throws DBException
     */
    public function isCancelRequested(int $jobId): bool;

    /**
     * Fails the running jobs of the queues whose worker is gone (e.g. killed for the memory): only one worker runs a
     * queue at a time, so the worker that has just started knows that there are no other ones.
     *
     * @param string[]|null $queues The default queue if not set.
     *
     * @return int The failed jobs count.
     *
     * @throws DBException
     * @throws InvalidQueueNameException
     */
    public function failRunning(string $error, ?array $queues = null): int;

    /**
     * @throws DBException
     */
    public function delete(int $jobId): void;
}
