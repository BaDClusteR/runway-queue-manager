<?php

declare(strict_types=1);

namespace Runway\QueueManager\Storage;

use Runway\DataStorage\DTO\DataStorageConnectionOptionsDTO;
use Runway\DataStorage\IDataStorageDriver;
use Runway\DataStorage\PDO;
use Runway\Env\Provider\IEnvVariablesProvider;
use Runway\Event\IEventDispatcher;
use Runway\Singleton\Container;

/**
 * The own database gets a plain PDO driver of Runway: the decorators of the application driver (e.g. the SQLite
 * pragmas of a project) are not for it, it may be another kind of database.
 */
class QueueStorage implements IQueueStorage {
    protected ?IDataStorageDriver $driver = null;

    /**
     * The ENV values come typed by the parser (e.g. a numeric password is an integer): they are cast here.
     *
     * @param mixed  $dsn         The own database; empty - the database of the application.
     * @param mixed  $tablePrefix The table name prefix in the own database.
     * @param string $table       The table name without the prefix.
     */
    public function __construct(
        protected IEnvVariablesProvider $envVariablesProvider,
        protected IEventDispatcher      $eventDispatcher,
        protected mixed                 $dsn = null,
        protected mixed                 $user = null,
        protected mixed                 $password = null,
        protected mixed                 $tablePrefix = null,
        protected string                $table = 'jobs'
    ) {}

    public function getDriver(): IDataStorageDriver {
        if ($this->driver === null) {
            if ($this->hasOwnDatabase()) {
                $driver = new PDO($this->envVariablesProvider, $this->eventDispatcher);
                $driver->connect(new DataStorageConnectionOptionsDTO(
                    dsn: (string)$this->dsn,
                    user: ((string)$this->user !== '') ? (string)$this->user : null,
                    password: ((string)$this->password !== '') ? (string)$this->password : null,
                    tableNamePrefix: (string)$this->tablePrefix
                ));

                $this->driver = $driver;
            } else {
                $this->driver = Container::getInstance()->getDataStorageDriver();
            }
        }

        return $this->driver;
    }

    public function getTable(): string {
        return '`{' . $this->table . '}`';
    }

    public function getDriverName(): string {
        $dsn = $this->hasOwnDatabase() ? (string)$this->dsn : $this->getDriver()->getConnectOptions()->dsn;

        return strtolower((string)strstr($dsn, ':', true));
    }

    public function hasOwnDatabase(): bool {
        return trim((string)$this->dsn) !== '';
    }
}
