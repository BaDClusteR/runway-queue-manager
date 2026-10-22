<?php

declare(strict_types=1);

namespace Runway\QueueManager\Storage;

use Runway\QueueManager\Exception\UnsupportedDatabaseException;

class QueueSchema implements IQueueSchema {
    /** PDO driver => the schema file. MariaDB has the "mysql" driver too. */
    protected const array SCHEMA_FILES = [
        'sqlite' => 'sqlite.sql',
        'mysql'  => 'mysql.sql',
    ];

    /**
     * The columns added by the newer versions of the library (they are in the schema files too): PDO driver => column
     * => its definition for ALTER TABLE ... ADD COLUMN.
     */
    protected const array ADDED_COLUMNS = [
        'sqlite' => [
            'attempts'  => 'INTEGER NOT NULL DEFAULT 0',
            'available' => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'mysql'  => [
            'attempts'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'available' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ],
    ];

    public function __construct(
        protected IQueueStorage $storage
    ) {}

    public function install(): void {
        $driver = $this->storage->getDriver();

        foreach ($this->getStatements() as $statement) {
            $driver->execute($statement);
        }

        $existing = $this->getColumns();

        foreach (static::ADDED_COLUMNS[$this->storage->getDriverName()] ?? [] as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $driver->execute("ALTER TABLE {$this->storage->getTable()} ADD COLUMN $column $definition");
            }
        }
    }

    public function getStatements(): array {
        $driverName = $this->storage->getDriverName();
        $file = static::SCHEMA_FILES[$driverName] ?? throw new UnsupportedDatabaseException(
            "The queue does not support \"$driverName\" databases, only: "
            . implode(', ', array_keys(static::SCHEMA_FILES))
        );

        // The comment lines are dropped, the statements end with ";" at the end of a line: PDO runs one at a time.
        $sql = (string)preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($this->getSchemaFolder() . "/$file"));
        $statements = array_filter(array_map('trim', preg_split('/;\s*$/m', $sql) ?: []));

        // The files have the default table name: another one (the queue_table parameter) replaces it.
        $table = trim($this->storage->getTable(), '`{}');

        return array_values(array_map(
            static fn(string $statement): string => str_replace('{jobs}', '{' . $table . '}', $statement),
            $statements
        ));
    }

    /**
     * @return string[] The column names of the queue table.
     */
    protected function getColumns(): array {
        $table = $this->storage->getTable();
        $driver = $this->storage->getDriver();

        return ($this->storage->getDriverName() === 'sqlite')
            ? array_column($driver->getResult("PRAGMA table_info($table)"), 'name')
            : array_column($driver->getResult("SHOW COLUMNS FROM $table"), 'Field');
    }

    protected function getSchemaFolder(): string {
        return dirname(__DIR__, 2) . '/sql';
    }
}
