<?php

declare(strict_types=1);

namespace Runway\QueueManager\Storage;

use Runway\DataStorage\Exception\DBException;
use Runway\QueueManager\Exception\UnsupportedDatabaseException;

/**
 * The queue table: sql/<driver>.sql of the library, for the driver of the queue database (see IQueueStorage).
 */
interface IQueueSchema {
    /**
     * Creates the table, unless it exists.
     *
     * @throws UnsupportedDatabaseException
     * @throws DBException
     */
    public function install(): void;

    /**
     * The statements install() runs (with the table placeholders: the driver adds the prefix).
     *
     * @return string[]
     *
     * @throws UnsupportedDatabaseException
     * @throws DBException
     */
    public function getStatements(): array;
}
