<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use JsonException;
use Runway\DataStorage\IDataStorageDriver;
use Runway\Exception\RuntimeException;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\Exception\InvalidQueueNameException;
use Runway\QueueManager\Storage\IQueueStorage;

/**
 * Plain SQL of SQLite and MySQL / MariaDB alike (no RETURNING: MariaDB has no UPDATE ... RETURNING). A status change
 * is a conditional UPDATE ("... WHERE status = 'queued'"), and the row is read after it: a job cannot be finished
 * twice, and a job cancelled meanwhile is not started. Only one worker runs at a time (see IQueueWorker), so two
 * workers never claim the same job.
 */
class JobQueue implements IJobQueue {
    protected const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param string $defaultQueue The queue of the jobs added without one, run by a worker without the queues.
     */
    public function __construct(
        protected IQueueStorage $storage,
        protected string        $defaultQueue = 'main'
    ) {}

    /**
     * @throws JsonException
     */
    public function enqueue(string $type, array $payload = [], ?string $queue = null): JobDTO {
        $queue = QueueName::check($queue ?? $this->defaultQueue);
        $now = time();
        $driver = $this->getDriver();

        $driver->execute(
            "INSERT INTO {$this->table()} (queue, type, status, payload, is_cancel_requested, created, updated)"
            . ' VALUES (:queue, :type, :status, :payload, 0, :now, :now)',
            [
                'queue'   => $queue,
                'type'    => $type,
                'status'  => JobStatus::QUEUED->value,
                'payload' => json_encode((object)$payload, static::JSON_FLAGS),
                'now'     => $now,
            ]
        );

        return $this->get((int)$driver->getLastInsertId())
            ?? throw new RuntimeException("The added job of type \"$type\" is not found.");
    }

    public function get(int $jobId): ?JobDTO {
        $row = $this->getDriver()->getFirstResult("SELECT * FROM {$this->table()} WHERE id = :id", ['id' => $jobId]);

        return $row ? $this->toDTO($row) : null;
    }

    public function list(int $limit = 50, ?array $queues = null): array {
        [$condition, $params] = ($queues !== null) ? $this->getQueueCondition($queues) : ['1 = 1', []];

        return array_map(
            $this->toDTO(...),
            $this->getDriver()->getResult(
                "SELECT * FROM {$this->table()} WHERE $condition ORDER BY id DESC LIMIT " . max(1, $limit),
                $params
            )
        );
    }

    public function claimNext(?array $queues = null): ?JobDTO {
        $driver = $this->getDriver();
        [$condition, $params] = $this->getQueueCondition($queues ?? [$this->defaultQueue]);

        // A job cancelled between the two queries is not claimed: the next one is tried.
        while ($jobId = $driver->getFirstScalarResult(
            "SELECT id FROM {$this->table()} WHERE status = :queued AND available <= :now AND $condition"
            . ' ORDER BY id LIMIT 1',
            [...$params, 'queued' => JobStatus::QUEUED->value, 'now' => time()]
        )) {
            $now = time();
            $driver->execute(
                "UPDATE {$this->table()} SET status = :running, attempts = attempts + 1, started = :now, updated = :now"
                . ' WHERE id = :id AND status = :queued',
                [
                    'running' => JobStatus::RUNNING->value,
                    'queued'  => JobStatus::QUEUED->value,
                    'id'      => $jobId,
                    'now'     => $now,
                ]
            );

            $job = $this->get((int)$jobId);

            if ($job?->status === JobStatus::RUNNING) {
                return $job;
            }
        }

        return null;
    }

    /**
     * @throws JsonException
     */
    public function removePayloadKeys(int $jobId, array $keys): void {
        $job = $this->get($jobId);

        if ($job === null || !array_intersect_key($job->payload, array_flip($keys))) {
            return;
        }

        $this->update($jobId, [
            'payload' => json_encode((object)array_diff_key($job->payload, array_flip($keys)), static::JSON_FLAGS),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function setProgress(int $jobId, array $progress): void {
        $this->update($jobId, ['progress' => json_encode($progress, static::JSON_FLAGS)]);
    }

    /**
     * @throws JsonException
     */
    public function complete(int $jobId, array $result): void {
        $this->finish($jobId, JobStatus::DONE, ['result' => json_encode($result, static::JSON_FLAGS)]);
    }

    public function fail(int $jobId, string $error): void {
        $this->finish($jobId, JobStatus::FAILED, ['error' => $error]);
    }

    /**
     * The progress of the failed attempt is dropped: the next one starts over.
     */
    public function retry(int $jobId, string $error, int $delay): void {
        $now = time();

        $this->getDriver()->execute(
            "UPDATE {$this->table()} SET status = :queued, error = :error, progress = NULL, started = NULL,"
            . ' available = :available, updated = :now WHERE id = :id AND status = :running',
            [
                'queued'    => JobStatus::QUEUED->value,
                'running'   => JobStatus::RUNNING->value,
                'error'     => $error,
                'available' => $now + max(0, $delay),
                'id'        => $jobId,
                'now'       => $now,
            ]
        );
    }

    public function markCancelled(int $jobId): void {
        $this->finish($jobId, JobStatus::CANCELLED);
    }

    public function requestCancel(int $jobId): bool {
        $job = $this->get($jobId);

        if ($job === null || $job->status->isFinished()) {
            return false;
        }

        $now = time();
        $driver = $this->getDriver();

        // A queued job has no handler yet: it's just cancelled. A running one is cancelled by its handler.
        $driver->execute(
            "UPDATE {$this->table()} SET status = :cancelled, finished = :now, updated = :now"
            . ' WHERE id = :id AND status = :queued',
            [
                'cancelled' => JobStatus::CANCELLED->value,
                'queued'    => JobStatus::QUEUED->value,
                'id'        => $jobId,
                'now'       => $now,
            ]
        );
        $driver->execute(
            "UPDATE {$this->table()} SET is_cancel_requested = 1, updated = :now WHERE id = :id AND status = :running",
            ['running' => JobStatus::RUNNING->value, 'id' => $jobId, 'now' => $now]
        );

        $job = $this->get($jobId);

        return $job !== null
            && ($job->status === JobStatus::CANCELLED
                || ($job->status === JobStatus::RUNNING && $job->isCancelRequested));
    }

    public function isCancelRequested(int $jobId): bool {
        return (bool)$this->getDriver()->getFirstScalarResult(
            "SELECT is_cancel_requested FROM {$this->table()} WHERE id = :id",
            ['id' => $jobId]
        );
    }

    public function failRunning(string $error, ?array $queues = null): int {
        $driver = $this->getDriver();
        [$condition, $params] = $this->getQueueCondition($queues ?? [$this->defaultQueue]);
        $running = [...$params, 'running' => JobStatus::RUNNING->value];
        // Only the worker that has just taken the locks of the queues calls it: no job of them starts between the two
        // queries.
        $count = (int)$driver->getFirstScalarResult(
            "SELECT COUNT(*) FROM {$this->table()} WHERE status = :running AND $condition",
            $running
        );

        if ($count) {
            $now = time();
            $driver->execute(
                "UPDATE {$this->table()} SET status = :failed, error = :error, finished = :now, updated = :now"
                . " WHERE status = :running AND $condition",
                [...$running, 'failed' => JobStatus::FAILED->value, 'error' => $error, 'now' => $now]
            );
        }

        return $count;
    }

    public function listFinishedBefore(int $finishedBefore, int $limit = 100): array {
        return array_map(
            $this->toDTO(...),
            $this->getDriver()->getResult(
                "SELECT * FROM {$this->table()} WHERE status IN (:done, :failed, :cancelled)"
                . ' AND finished < :before ORDER BY finished, id LIMIT ' . max(1, $limit),
                [
                    'done'      => JobStatus::DONE->value,
                    'failed'    => JobStatus::FAILED->value,
                    'cancelled' => JobStatus::CANCELLED->value,
                    'before'    => $finishedBefore,
                ]
            )
        );
    }

    public function delete(int $jobId): void {
        $this->getDriver()->execute("DELETE FROM {$this->table()} WHERE id = :id", ['id' => $jobId]);
    }

    /**
     * Only a running job is finished: e.g. a job cancelled meanwhile is not marked done.
     *
     * @param array<string, mixed> $values Column => value.
     */
    protected function finish(int $jobId, JobStatus $status, array $values = []): void {
        $now = time();
        $sets = ['status = :status', 'finished = :now', 'updated = :now'];

        foreach (array_keys($values) as $column) {
            $sets[] = "$column = :$column";
        }

        $this->getDriver()->execute(
            "UPDATE {$this->table()} SET " . implode(', ', $sets) . ' WHERE id = :id AND status = :running',
            [
                ...$values,
                'status'  => $status->value,
                'running' => JobStatus::RUNNING->value,
                'id'      => $jobId,
                'now'     => $now,
            ]
        );
    }

    /**
     * @param array<string, mixed> $values Column => value: the columns are always set in this class, never taken from
     *                                    the input.
     */
    protected function update(int $jobId, array $values): void {
        $sets = ['updated = :now'];

        foreach (array_keys($values) as $column) {
            $sets[] = "$column = :$column";
        }

        $this->getDriver()->execute(
            "UPDATE {$this->table()} SET " . implode(', ', $sets) . ' WHERE id = :id',
            [...$values, 'id' => $jobId, 'now' => time()]
        );
    }

    /**
     * @param string[] $queues
     *
     * @return array{string, array<string, string>} "queue IN (:queue0, ...)" and its parameters.
     *
     * @throws InvalidQueueNameException
     */
    protected function getQueueCondition(array $queues): array {
        $params = [];

        foreach (array_values(QueueName::checkAll($queues)) as $index => $queue) {
            $params["queue$index"] = $queue;
        }

        return $params
            ? ['queue IN (:' . implode(', :', array_keys($params)) . ')', $params]
            : ['1 = 0', []];
    }

    protected function toDTO(array $row): JobDTO {
        return new JobDTO(
            id: (int)$row['id'],
            queue: (string)$row['queue'],
            type: (string)$row['type'],
            status: JobStatus::tryFrom((string)$row['status']) ?? JobStatus::FAILED,
            payload: $this->decode($row['payload']) ?? [],
            progress: $this->decode($row['progress']),
            result: $this->decode($row['result']),
            error: ($row['error'] !== null) ? (string)$row['error'] : null,
            isCancelRequested: (bool)$row['is_cancel_requested'],
            created: (int)$row['created'],
            started: ($row['started'] !== null) ? (int)$row['started'] : null,
            finished: ($row['finished'] !== null) ? (int)$row['finished'] : null,
            // A table of an older version has no such columns until a worker starts (see IQueueSchema::install()).
            attempts: (int)($row['attempts'] ?? 0),
            available: (int)($row['available'] ?? 0)
        );
    }

    protected function decode(mixed $json): ?array {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode((string)$json, true);

        return is_array($data) ? $data : null;
    }

    protected function table(): string {
        return $this->storage->getTable();
    }

    protected function getDriver(): IDataStorageDriver {
        return $this->storage->getDriver();
    }
}
