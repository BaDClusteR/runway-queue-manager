<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\QueueManager\Exception\InvalidQueueNameException;

interface IQueueWorkerLauncher {
    /**
     * Starts the worker (the "queue:work" command) in the background, e.g. after a job is added from the web: then
     * the job starts at once, without a cron job. A second worker of a queue exits by itself if one is running.
     *
     * @param string|null $queue The queue of the added job; the default one if not set.
     *
     * @return bool False if the launch is disabled or impossible (then the jobs wait for the cron job).
     *
     * @throws InvalidQueueNameException
     */
    public function launch(?string $queue = null): bool;
}
