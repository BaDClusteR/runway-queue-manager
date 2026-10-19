<?php

declare(strict_types=1);

namespace Runway\QueueManager\Exception;

use Runway\Exception\Exception;

/**
 * The queue database is neither SQLite nor MySQL / MariaDB (see IQueueSchema).
 */
class UnsupportedDatabaseException extends Exception {
}
