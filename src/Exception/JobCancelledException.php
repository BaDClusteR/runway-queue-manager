<?php

declare(strict_types=1);

namespace Runway\QueueManager\Exception;

use Runway\Exception\Exception;

/**
 * Thrown by JobContext::reportProgress() when the job is cancelled: the handler stops (and cleans up, e.g. in
 * "finally"), the worker marks the job cancelled.
 */
class JobCancelledException extends Exception {
}
