<?php

declare(strict_types=1);

namespace Runway\QueueManager\Storage;

use Runway\DataStorage\Exception\DBConnectionException;
use Runway\DataStorage\IDataStorageDriver;

/**
 * The database of the queue table: its own one (QUEUE_DSN, QUEUE_DB_USER, QUEUE_DB_PASSWORD, QUEUE_DB_PREFIX), or the
 * database of the application if QUEUE_DSN is empty. SQLite and MySQL / MariaDB are supported (see IQueueSchema).
 */
interface IQueueStorage {
    /**
     * Connected on the first call.
     *
     * @throws DBConnectionException
     */
    public function getDriver(): IDataStorageDriver;

    /**
     * The table for the queries: "`{jobs}`" (the driver adds the prefix).
     */
    public function getTable(): string;

    /**
     * The PDO driver of the DSN: "sqlite", "mysql", etc.
     *
     * @throws DBConnectionException
     */
    public function getDriverName(): string;

    /**
     * Whether the queue has its own database (QUEUE_DSN).
     */
    public function hasOwnDatabase(): bool;
}
