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

    public function __construct(
        protected IQueueStorage $storage
    ) {}

    public function install(): void {
        foreach ($this->getStatements() as $statement) {
            $this->storage->getDriver()->execute($statement);
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

    protected function getSchemaFolder(): string {
        return dirname(__DIR__, 2) . '/sql';
    }
}
