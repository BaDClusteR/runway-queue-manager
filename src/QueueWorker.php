<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\Exception\RuntimeException;
use Runway\Logger\ILogger;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Exception\JobCancelledException;
use Runway\QueueManager\Handler\IJobHandler;
use Runway\QueueManager\Handler\IJobHandlerProvider;
use Runway\QueueManager\Handler\IRetryingJobHandler;
use Runway\QueueManager\Storage\IQueueSchema;
use Throwable;

/**
 * The worker holds an exclusive lock of a file of every queue it runs ("<queue>.lock") while it runs: so a second
 * worker of a queue exits at once, and the jobs of a queue left "running" without the lock are the jobs of a worker
 * that has died (see IJobQueue::failRunning()). The workers of different queues run side by side.
 *
 * A failed job of a retrying handler (see IRetryingJobHandler) is queued again with a delay, and logged as info: only
 * its last failure is an error. The job ID is in the context of the log record, not in its text: the repeated failures
 * read the same (e.g. a log watcher of the application can group them).
 */
class QueueWorker implements IQueueWorker {
    /**
     * @param string            $defaultQueue The queue run by the worker without the queues.
     * @param string|null       $lockFolder   Empty: var/queue of the project (PROJECT_ROOT, or the current folder).
     * @param IQueueSchema|null $schema       Updates the table of an older version of the library when the worker
     *                                        starts; null - it's up to date.
     */
    public function __construct(
        protected IJobQueue           $queue,
        protected IJobHandlerProvider $handlerProvider,
        protected ILogger             $logger,
        protected string              $defaultQueue = 'main',
        protected ?string             $lockFolder = null,
        protected ?IQueueSchema       $schema = null
    ) {
        if ((string)$this->lockFolder === '') {
            $this->lockFolder = $this->getProjectRoot() . '/var/queue';
        }
    }

    public function run(?callable $onEvent = null, bool $once = false, ?array $queues = null): ?int {
        $queues = QueueName::checkAll($queues ?? [$this->defaultQueue]);
        $locks = $this->lock($queues);

        if ($locks === null) {
            return null;
        }

        try {
            $this->schema?->install();
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

        // A job to be retried needs its secrets for the next attempt: they are removed when it's over.
        $isRetrying = $handler instanceof IRetryingJobHandler;

        if (!$isRetrying) {
            $this->removeSecrets($job, $handler);
        }

        $log(($job->attempts > 1) ? "started, attempt $job->attempts." : 'started.');

        try {
            $this->queue->complete($job->id, $handler->handle($job, new JobContext($this->queue, $job->id)));
            $log('done.');
        } catch (JobCancelledException) {
            $this->queue->markCancelled($job->id);
            $log('cancelled.');
        } catch (Throwable $e) {
            $context = [
                'jobId'     => $job->id,
                'attempt'   => $job->attempts,
                'exception' => get_debug_type($e),
                'trace'     => $this->formatTrace($e),
            ];
            $delay = $this->getRetryDelay($job, $handler);

            if ($delay !== null) {
                $this->logger->info("Job of type \"$job->type\" failed, to be retried: {$e->getMessage()}", $context);
                $this->queue->retry($job->id, $e->getMessage(), $delay);
                $log("failed, retry in {$delay}s: {$e->getMessage()}");

                return;
            }

            $this->logger->error("Job of type \"$job->type\" failed: {$e->getMessage()}", $context);
            $this->queue->fail($job->id, $e->getMessage());
            $log("failed: {$e->getMessage()}");
        }

        if ($isRetrying) {
            $this->removeSecrets($job, $handler);
        }
    }

    /**
     * @return int|null The delay before the next attempt (seconds), null if the job is not retried.
     */
    protected function getRetryDelay(JobDTO $job, IJobHandler $handler): ?int {
        if (!$handler instanceof IRetryingJobHandler) {
            return null;
        }

        // The 1st attempt has failed: the 1st delay.
        $delay = array_values($handler->getRetryDelays())[$job->attempts - 1] ?? null;

        return ($delay !== null) ? max(0, (int)$delay) : null;
    }

    /**
     * Like Throwable::getTraceAsString(), but without the argument values: they can be secrets (e.g. a password of
     * the payload passed to a connection), and they are logged unless zend.exception_ignore_args is on.
     */
    protected function formatTrace(Throwable $e): string {
        $lines = [];

        foreach ($e->getTrace() as $index => $frame) {
            $location = isset($frame['file'])
                ? "{$frame['file']}(" . ($frame['line'] ?? 0) . ')'
                : '[internal function]';
            $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];

            $lines[] = "#$index $location: $function()";
        }

        $lines[] = '#' . count($lines) . ' {main}';

        return implode("\n", $lines);
    }

    protected function getProjectRoot(): string {
        $root = defined('PROJECT_ROOT') ? constant('PROJECT_ROOT') : getcwd();

        return is_string($root) ? $root : '.';
    }

    protected function removeSecrets(JobDTO $job, IJobHandler $handler): void {
        if ($keys = $handler->getSecretPayloadKeys()) {
            $this->queue->removePayloadKeys($job->id, $keys);
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
