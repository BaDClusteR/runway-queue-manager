<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use LogicException;
use Runway\DataStorage\DTO\DataStorageConnectionOptionsDTO;
use Runway\DataStorage\IDataStorageDriver;

/**
 * Remembers the executed statements; the results of the queries are given (query => rows).
 */
final class RecordingDriver implements IDataStorageDriver {
    /** @var string[] */
    public array $executed = [];

    /**
     * @param array<string, array<array<string, mixed>>> $results
     */
    public function __construct(
        protected array $results = []
    ) {}

    public function connect(DataStorageConnectionOptionsDTO $options): mixed {
        return null;
    }

    public function getResult(string $query, mixed $vars = []): array {
        return $this->results[$query] ?? throw new LogicException("Unexpected query: $query");
    }

    public function getResultsIterator(string $query, mixed $vars = []): iterable {
        return $this->getResult($query, $vars);
    }

    public function execute(string $query, mixed $vars = []): void {
        $this->executed[] = $query;
    }

    public function getFirstResult(string $query, mixed $vars = []): array {
        return $this->getResult($query, $vars)[0] ?? [];
    }

    public function getColumn(string $query, mixed $vars = [], int|string $columnName = ''): array {
        return array_column($this->getResult($query, $vars), $columnName);
    }

    public function getFirstScalarResult(string $query, mixed $vars = []): mixed {
        $row = $this->getFirstResult($query, $vars);

        return $row ? reset($row) : null;
    }

    public function getLastInsertId(): string {
        return '0';
    }

    public function getConnectOptions(): DataStorageConnectionOptionsDTO {
        return new DataStorageConnectionOptionsDTO('fake:');
    }
}
