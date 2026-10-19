<?php

declare(strict_types=1);

namespace Runway\QueueManager\Enum;

enum JobStatus: string {
    case QUEUED = 'queued';

    case RUNNING = 'running';

    case DONE = 'done';

    case FAILED = 'failed';

    case CANCELLED = 'cancelled';

    public function isFinished(): bool {
        return in_array($this, [self::DONE, self::FAILED, self::CANCELLED], true);
    }
}
