<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\Logger\ILogger;
use Runway\QueueManager\Handler\IJobHandlerProvider;
use Throwable;

class QueuePruner implements IQueuePruner {
    /** The jobs are read in pages: a big backlog does not take the memory at once. */
    protected const int PAGE_SIZE = 100;

    public function __construct(
        protected IJobQueue           $queue,
        protected IJobHandlerProvider $handlerProvider,
        protected ILogger             $logger
    ) {}

    public function prune(int $finishedBefore): int {
        $count = 0;

        // Every page is deleted before the next one is read: the next query starts from the remaining ones.
        while ($jobs = $this->queue->listFinishedBefore($finishedBefore, static::PAGE_SIZE)) {
            foreach ($jobs as $job) {
                try {
                    // A job of a type the application no longer has is just deleted.
                    $this->handlerProvider->getHandler($job->type)?->cleanUp($job);
                } catch (Throwable $e) {
                    $this->logger->warning(
                        "The clean-up of a job of type \"$job->type\" failed: {$e->getMessage()}",
                        ['jobId' => $job->id, 'exception' => get_debug_type($e)]
                    );
                }

                $this->queue->delete($job->id);
                $count++;
            }
        }

        return $count;
    }
}
