<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\DataStorage\Exception\DBException;
use Runway\Exception\RuntimeException;
use Runway\QueueManager\Exception\InvalidQueueNameException;

/**
 * Runs the queued jobs of its queues one by one (see IJobHandler). Only one worker runs a queue at a time (a lock
 * file): the jobs are often heavy (e.g. an export or a backup), and a small server would not cope with several ones
 * at once. The workers of different queues run side by side.
 */
interface IQueueWorker {
    /**
     * Runs the queued jobs until there are none.
     *
     * @param (callable(string $message): void)|null $onEvent For the log of the console command.
     * @param bool                                     $once    Run one job only.
     * @param string[]|null                            $queues  The default queue if not set.
     *
     * @return int|null The job runs (a retry without a delay is run by the same worker, every attempt counts), null if
     *                  another worker is running one of the queues.
     *
     * @throws RuntimeException If a lock file cannot be created.
     * @throws InvalidQueueNameException
     * @throws DBException
     */
    public function run(?callable $onEvent = null, bool $once = false, ?array $queues = null): ?int;

    /**
     * Whether a worker runs one of the queues.
     *
     * @param string[]|null $queues The default queue if not set.
     *
     * @throws RuntimeException If a lock file cannot be created.
     * @throws InvalidQueueNameException
     */
    public function isRunning(?array $queues = null): bool;
}
