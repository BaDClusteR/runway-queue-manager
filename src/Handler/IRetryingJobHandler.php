<?php

declare(strict_types=1);

namespace Runway\QueueManager\Handler;

/**
 * A handler whose failed jobs are run again, e.g. a notification sent to a service that is down for a while. The
 * other handlers' jobs fail at once.
 *
 * A retried job is queued again with a delay: the worker that has run it does not wait for it, the next worker runs
 * it (the cron one, see the queue:work command). The cancellation and the progress work as for a new job.
 */
interface IRetryingJobHandler extends IJobHandler {
    /**
     * The delays before the retries, in seconds: e.g. [60, 300, 1800] - the job is run up to 4 times, the 2nd run a
     * minute after the 1st one fails, and so on. The job fails when they are over.
     *
     * @return int[]
     */
    public function getRetryDelays(): array;
}
