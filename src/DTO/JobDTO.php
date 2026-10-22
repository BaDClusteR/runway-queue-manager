<?php

declare(strict_types=1);

namespace Runway\QueueManager\DTO;

use Runway\QueueManager\Enum\JobStatus;

readonly class JobDTO {
    /**
     * @param string      $queue     The queue the job is in, see IJobQueue::enqueue().
     * @param array       $payload   The job parameters, see IJobQueue::enqueue().
     * @param array|null  $progress  The last progress reported by the handler, see JobContext::reportProgress().
     * @param array|null  $result    The result of a done job, see IJobHandler::handle().
     * @param string|null $error     The error of a failed job, or of the last attempt of a job to be retried.
     * @param int         $created   Unix timestamp, like the other dates.
     * @param int         $attempts  The runs of the job, the current one too (see IRetryingJobHandler).
     * @param int         $available A queued job is not run before it (the delay of a retry), 0 - at once.
     */
    public function __construct(
        public int       $id,
        public string    $queue,
        public string    $type,
        public JobStatus $status,
        public array     $payload,
        public ?array    $progress,
        public ?array    $result,
        public ?string   $error,
        public bool      $isCancelRequested,
        public int       $created,
        public ?int      $started,
        public ?int      $finished,
        public int       $attempts = 0,
        public int       $available = 0
    ) {}
}
