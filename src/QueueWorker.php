<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\Exception\RuntimeException;
use Runway\Logger\ILogger;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Exception\JobCancelledException;
use Runway\QueueManager\Handler\IJobHandlerProvider;
use Throwable;

/**
 * The worker holds an exclusive lock of a file of every queue it runs ("<queue>.lock") while it runs: so a second
 * worker of a queue exits at once, and the jobs of a queue left "running" without the lock are the jobs of a worker
 * that has died (see IJobQueue::failRunning()). The workers of different queues run side by side.
 */
class QueueWorker implements IQueueWorker {
    /**
     * @param string      $defaultQueue The queue run by the worker without the queues.
     * @param string|null $lockFolder   Empty: var/queue of the project (PROJECT_ROOT, or the current folder).
     */
    public function __construct(
        protected IJobQueue           $queue,
        protected IJobHandlerProvider $handlerProvider,
        protected ILogger             $logger,
        protected string              $defaultQueue = 'main',
        protected ?string             $lockFolder = null
    ) {
        if ((string)$this->lockFolder === '') {
            $this->lockFolder = (defined('PROJECT_ROOT') ? (string)constant('PROJECT_ROOT') : (string)getcwd())
                . '/var/queue';
        }
    }

    public function run(?callable $onEvent = null, bool $once = false, ?array $queues = null): ?int {
        $queues = QueueName::checkAll($queues ?? [$this->defaultQueue]);
        $locks = $this->lock($queues);

        if ($locks === null) {
            return null;
        }

        try {
            $failedCount = $this->queue->failRunning('The worker stopped while running the job.', $queues);

            if ($failedCount && $onEvent) {
                $onEvent("$failedCount job(s) of a stopped worker marked failed.");
            }

            $jobsCount = 0;

            while ($job = $this->queue->claimNext($queues)) {
                $this->runJob($job, $onEvent);
                $jobsCount++;

                if ($once) {
                    break;
                }
            }

            return $jobsCount;
        } finally {
            $this->unlock($locks);
        }
    }

    public function isRunning(?array $queues = null): bool {
        $locks = $this->lock(QueueName::checkAll($queues ?? [$this->defaultQueue]));

        if ($locks === null) {
            return true;
        }

        $this->unlock($locks);

        return false;
    }

    protected function runJob(JobDTO $job, ?callable $onEvent): void {
        $log = static fn(string $message) => $onEvent && $onEvent("Job #$job->id ($job->type, $job->queue): $message");
        $handler = $this->handlerProvider->getHandler($job->type);

        if ($handler === null) {
            $this->queue->fail($job->id, "Unknown job type \"$job->type\".");
            $log('failed, unknown type.');

            return;
        }

        $this->queue->removePayloadKeys($job->id, $handler->getSecretPayloadKeys());
        $log('started.');

        try {
            $this->queue->complete($job->id, $handler->handle($job, new JobContext($this->queue, $job->id)));
            $log('done.');
        } catch (JobCancelledException) {
            $this->queue->markCancelled($job->id);
            $log('cancelled.');
        } catch (Throwable $e) {
            $this->logger->error(
                "Job #$job->id ($job->type) failed: {$e->getMessage()}",
                ['exception' => get_debug_type($e), 'trace' => $e->getTraceAsString()]
            );
            $this->queue->fail($job->id, $e->getMessage());
            $log("failed: {$e->getMessage()}");
        }
    }

    /**
     * All or none: the locks taken are released if one of the queues is locked by another worker.
     *
     * @param string[] $queues
     *
     * @return resource[]|null Null if another worker holds a lock.
     *
     * @throws RuntimeException
     */
    protected function lock(array $queues): ?array {
        $folder = (string)$this->lockFolder;

        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new RuntimeException("Cannot create folder $folder.");
        }

        $locks = [];

        foreach ($queues as $queue) {
            $path = "$folder/$queue.lock";
            $lock = fopen($path, 'c');

            if ($lock === false) {
                $this->unlock($locks);

                throw new RuntimeException("Cannot open the lock file $path.");
            }

            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                $this->unlock($locks);

                return null;
            }

            $locks[] = $lock;
        }

        return $locks;
    }

    /**
     * @param resource[] $locks
     */
    protected function unlock(array $locks): void {
        foreach ($locks as $lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
