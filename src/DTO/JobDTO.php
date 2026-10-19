<?php

declare(strict_types=1);

namespace Runway\QueueManager\DTO;

use Runway\QueueManager\Enum\JobStatus;

readonly class JobDTO {
    /**
     * @param string     $queue    The queue the job is in, see IJobQueue::enqueue().
     * @param array      $payload  The job parameters, see IJobQueue::enqueue().
     * @param array|null $progress The last progress reported by the handler, see JobContext::reportProgress().
     * @param array|null $result   The result of a done job, see IJobHandler::handle().
     * @param int        $created  Unix timestamp, like the other dates.
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
        public ?int      $finished
    ) {}
}
