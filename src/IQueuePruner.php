<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\DataStorage\Exception\DBException;

/**
 * Deletes the old finished jobs (done, failed, cancelled): the queue keeps them for their results and errors, and a
 * queue of many small jobs (e.g. notifications) would grow for ever. Run it by cron, see the queue:prune command.
 */
interface IQueuePruner {
    /**
     * What the jobs have made is deleted with them (see IJobHandler::cleanUp()): a failed clean-up is logged, the job
     * is deleted anyway.
     *
     * @param int $finishedBefore Unix timestamp: the jobs finished before it are deleted.
     *
     * @return int The deleted jobs count.
     *
     * @throws DBException
     */
    public function prune(int $finishedBefore): int;
}
