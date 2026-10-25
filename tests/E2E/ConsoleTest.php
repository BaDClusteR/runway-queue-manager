<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Tests\Support\Project;

/**
 * The queue of a project through its console script, in separate processes: the commands, the worker started in the
 * background, the workers running side by side, a killed worker.
 */
class ConsoleTest extends TestCase {
    protected Project $project;

    protected function setUp(): void {
        $this->project = new Project();
    }

    protected function tearDown(): void {
        $this->project->remove();
    }

    public function testCommands(): void {
        [$exitCode, $stdout] = $this->project->run(['queue:install', '--print']);
        self::assertSame(0, $exitCode);
        self::assertStringStartsWith("CREATE TABLE IF NOT EXISTS `{jobs}` (\n", $stdout);
        self::assertStringContainsString("CREATE INDEX IF NOT EXISTS `{jobs}_queue_status_IDX`", $stdout);
        self::assertSame([], $this->project->getTables('app.sqlite'));

        self::assertSame(
            [0, " The queue table is ready (sqlite, the database of the application). \n", ''],
            $this->project->run(['queue:install'])
        );
        self::assertSame([0, "There are no jobs.\n", ''], $this->project->run(['queue:list']));

        $echo = $this->enqueue('echo', ['name' => 'Тест']);
        $fail = $this->enqueue('fail', ['message' => 'Disk is full']);
        $unknown = $this->enqueue('unknown');

        [$exitCode, $stdout, $stderr] = $this->project->run(['queue:work']);
        self::assertSame(0, $exitCode);
        self::assertSame('', $stderr);
        self::assertSame(
            "Job #$echo (echo, main): started.\n"
            . "Job #$echo (echo, main): done.\n"
            . "Job #$fail (fail, main): started.\n"
            . "Job #$fail (fail, main): failed: Disk is full\n"
            . "Job #$unknown (unknown, main): failed, unknown type.\n"
            . " Jobs run: 3. \n",
            $stdout
        );
        self::assertSame('{"name":"Тест"}', $this->project->getJob($echo)['result'] ?? null);

        [, $stdout] = $this->project->run(['queue:list', '--limit=2']);
        self::assertMatchesRegularExpression("/│ $unknown +│ main +│ unknown +│ failed +│/u", $stdout);
        self::assertMatchesRegularExpression(
            "/│ $fail +│ main +│ fail +│ failed +│ .+│ Disk is full +│/u",
            $stdout
        );
        self::assertStringNotContainsString('echo', $stdout);

        // A typo is not 0 days: nothing is deleted.
        [$exitCode, $stdout, $stderr] = $this->project->run(['queue:prune', '--days=thirty']);
        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertSame(" Option --days must be a non-negative integer. \n", $stderr);

        $this->project->execute('UPDATE jobs SET finished = finished - 86400 * 2 WHERE id IN (:a, :b)', [
            'a' => $echo,
            'b' => $fail,
        ]);
        self::assertSame([0, " Jobs deleted: 2. \n", ''], $this->project->run(['queue:prune', '--days=1']));
        self::assertNull($this->project->getJob($echo));
        self::assertNotNull($this->project->getJob($unknown));
    }

    public function testInvalidQueue(): void {
        $this->project->run(['queue:install']);

        [$exitCode, $stdout, $stderr] = $this->project->run(['queue:work', '--queue=../main']);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Invalid queue name "../main"', $stderr);
        self::assertFileDoesNotExist($this->project->root . '/var/main.lock');
    }

    /**
     * QUEUE_DSN: the table is in the own database of the queue.
     */
    public function testOwnDatabase(): void {
        $this->project->remove();
        $this->project = new Project(['QUEUE_DSN' => 'sqlite:{root}/queue.sqlite']);

        self::assertSame(
            [0, " The queue table is ready (sqlite, QUEUE_DSN). \n", ''],
            $this->project->run(['queue:install'])
        );
        $job = $this->enqueue('echo');
        $this->project->run(['queue:work']);

        self::assertSame('done', $this->project->getJob($job, 'queue.sqlite')['status'] ?? null);
        self::assertNotContains('jobs', $this->project->getTables('app.sqlite'));
    }

    /**
     * The worker started by the request that adds the job (see IQueueWorkerLauncher): the job is run without cron.
     */
    public function testLaunchedWorkerRunsJob(): void {
        $this->project->run(['queue:install']);

        [$exitCode, $stdout] = $this->project->run(
            ['test:enqueue', 'echo', '--payload={"a":1}', '--queue=media', '--launch']
        );
        self::assertSame(0, $exitCode);
        [$id, $launched] = explode("\n", trim($stdout));

        if (PHP_OS_FAMILY === 'Windows') {
            // The jobs wait for the scheduled worker.
            self::assertSame('not launched', $launched);
            self::assertSame('queued', $this->project->getJob((int)$id)['status'] ?? null);

            return;
        }

        self::assertSame('launched', $launched);
        $this->project->waitFor(
            fn(): bool => ($this->project->getJob((int)$id)['status'] ?? null) === 'done',
            'The launched worker runs the job.'
        );
        self::assertSame('{"a":1}', $this->project->getJob((int)$id)['result'] ?? null);
        self::assertFileExists($this->project->root . '/var/queue/media.lock');
    }

    /**
     * One worker runs a queue at a time; the workers of other queues run side by side. A running job is cancelled from
     * another process.
     */
    public function testWorkersAndCancellation(): void {
        $this->project->run(['queue:install']);
        $long = $this->enqueue('sleep', ['steps' => 300, 'ms' => 100]);
        $queued = $this->enqueue('echo');
        $other = $this->enqueue('echo', [], 'other');
        $worker = $this->project->start(['queue:work']);
        $this->waitForStep($long);

        self::assertSame(
            [0, "Another worker is running: it will run the queued jobs.\n", ''],
            $this->project->run(['queue:work'])
        );
        self::assertSame(
            [0, "Another worker is running: it will run the queued jobs.\n", ''],
            $this->project->run(['queue:work', '--queue=other,main'])
        );
        [$exitCode, $stdout] = $this->project->run(['queue:work', '--queue=other']);
        self::assertSame(0, $exitCode);
        self::assertStringEndsWith(" Jobs run: 1. \n", $stdout);
        self::assertSame('done', $this->project->getJob($other)['status'] ?? null);
        self::assertSame('running', $this->project->getJob($long)['status'] ?? null);

        self::assertSame([0, "cancelled\n", ''], $this->project->run(['test:cancel', (string)$long]));

        self::assertSame(0, $this->project->waitForExit($worker));
        self::assertSame('cancelled', $this->project->getJob($long)['status'] ?? null);
        // The worker goes on with the next job.
        self::assertSame('done', $this->project->getJob($queued)['status'] ?? null);
        self::assertSame([0, "finished\n", ''], $this->project->run(['test:cancel', (string)$long]));
    }

    /**
     * The job of a killed worker (e.g. for the memory) fails when the next worker starts, without its secrets.
     */
    public function testKilledWorker(): void {
        $this->project->run(['queue:install']);
        $job = $this->enqueue('retrying-sleep', ['steps' => 300, 'ms' => 100, 'password' => 'secret', 'name' => 'x']);
        $worker = $this->project->start(['queue:work']);
        $this->waitForStep($job);
        // A job to be retried has its secrets while it runs.
        self::assertSame(
            '{"steps":300,"ms":100,"password":"secret","name":"x"}',
            $this->project->getJob($job)['payload'] ?? null
        );

        $this->project->kill($worker);

        [$exitCode, $stdout] = $this->project->run(['queue:work']);
        self::assertSame(0, $exitCode);
        self::assertSame("1 job(s) of a stopped worker marked failed.\n Jobs run: 0. \n", $stdout);
        $failed = $this->project->getJob($job);
        self::assertSame('failed', $failed['status'] ?? null);
        self::assertSame('The worker stopped while running the job.', $failed['error'] ?? null);
        self::assertSame('{"steps":300,"ms":100,"name":"x"}', $failed['payload'] ?? null);
    }

    /**
     * The secrets of a job are removed when it starts, and when it's cancelled before.
     */
    public function testSecrets(): void {
        $this->project->run(['queue:install']);
        $run = $this->enqueue('sleep', ['steps' => 1, 'ms' => 1, 'password' => 'secret']);
        $cancelled = $this->enqueue('sleep', ['password' => 'secret'], 'other');

        self::assertSame([0, "cancelled\n", ''], $this->project->run(['test:cancel', (string)$cancelled]));
        $this->project->run(['queue:work']);

        self::assertSame('done', $this->project->getJob($run)['status'] ?? null);
        self::assertSame('{"steps":1,"ms":1}', $this->project->getJob($run)['payload'] ?? null);
        self::assertSame('{}', $this->project->getJob($cancelled)['payload'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function enqueue(string $type, array $payload = [], ?string $queue = null): int {
        $arguments = ['test:enqueue', $type, '--payload=' . json_encode((object)$payload, JSON_THROW_ON_ERROR)];

        if ($queue !== null) {
            $arguments[] = "--queue=$queue";
        }

        [$exitCode, $stdout, $stderr] = $this->project->run($arguments);
        self::assertSame(0, $exitCode, $stderr);

        return (int)$stdout;
    }

    /**
     * Until the job has reported its first step: it's running.
     */
    protected function waitForStep(int $id): void {
        $this->project->waitFor(
            fn(): bool => ($this->project->getJob($id)['progress'] ?? null) !== null,
            "Job #$id is running."
        );
    }
}
