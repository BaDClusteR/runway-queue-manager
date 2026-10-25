<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Runway\DataStorage\IDataStorageDriver;
use Runway\QueueManager\Storage\IQueueStorage;

/**
 * A storage of any kind of database, e.g. MySQL without a server (see RecordingDriver).
 */
final class FakeStorage implements IQueueStorage {
    public function __construct(
        public IDataStorageDriver $driver,
        public string             $driverName,
        public string             $table = 'jobs',
        public bool               $isOwnDatabase = false
    ) {}

    public function getDriver(): IDataStorageDriver {
        return $this->driver;
    }

    public function getTable(): string {
        return '`{' . $this->table . '}`';
    }

    public function getDriverName(): string {
        return $this->driverName;
    }

    public function hasOwnDatabase(): bool {
        return $this->isOwnDatabase;
    }
}
