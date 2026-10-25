<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Runway\Console\Exception\InvalidParameterException;
use Runway\Console\Parameter\DTO\ParameterDTO;
use Runway\QueueManager\Command\ListJobsCommand;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Tests\Fixture\TestStorage;
use Runway\QueueManager\Tests\Support\CommandRunner;
use Runway\QueueManager\Tests\Support\JobAssertions;

class ListJobsCommandTest extends TestCase {
    use JobAssertions;

    protected IQueueStorage $storage;

    protected JobQueue $queue;

    protected function setUp(): void {
        $this->storage = TestStorage::sqlite();
        $this->queue = new JobQueue($this->storage);
    }

    public function testDefinition(): void {
        $command = new ListJobsCommand($this->queue);

        self::assertSame('queue:list', $command->getName());
        self::assertSame('Show the recent jobs with their statuses', $command->getDescription());
        self::assertSame('Queue', $command->getGroup());
        self::assertSame(
            [
                ['queue', 'q', null, 'Only the jobs of these queues, comma-separated (all the queues if not set)'],
                ['limit', 'l', '20', 'How many jobs to show'],
            ],
            array_map(
                static fn(ParameterDTO $option): array => [
                    $option->getName(),
                    $option->getShortcut(),
                    $option->getDefault(),
                    $option->getDescription(),
                ],
                $command->getParameters()
            )
        );
    }

    public function testNoJobs(): void {
        [$exitCode, $output] = CommandRunner::run(new ListJobsCommand($this->queue));

        self::assertSame(0, $exitCode);
        self::assertSame("There are no jobs.\n", $output->stdout);
    }

    public function testStatuses(): void {
        $done = $this->queue->enqueue('export', [], 'media');
        $this->queue->claimNext(['media']);
        $this->queue->complete($done->id, []);
        $cancelling = $this->queue->enqueue('<fg=red>backup</>');
        $this->queue->claimNext();
        $this->queue->requestCancel($cancelling->id);
        $retried = $this->queue->enqueue('notify');
        $this->queue->claimNext();
        $this->queue->retry($retried->id, 'Service is <down>', 0);
        $this->queue->claimNext();
        $this->queue->retry($retried->id, 'Service is <down>', 600);
        $this->setTimes(1_700_000_000);

        [$exitCode, $output] = CommandRunner::run(new ListJobsCommand($this->queue));

        self::assertSame(0, $exitCode);
        $lines = explode("\n", $output->stdout);
        self::assertSame(
            ['ID', 'Queue', 'Type', 'Status', 'Created', 'Finished', 'Error'],
            $this->getCells($lines[1])
        );
        // The newest first; the values are escaped.
        self::assertSame(
            [
                (string)$retried->id,
                'main',
                'notify',
                'queued (retry at ' . date('H:i:s', self::found($this->queue->get($retried->id))->available)
                . '), 2 attempts',
                date('Y-m-d H:i:s', 1_700_000_000),
                '',
                'Service is <down>',
            ],
            $this->getCells($lines[3])
        );
        self::assertSame(
            [(string)$cancelling->id, 'main', '<fg=red>backup</>', 'running (cancelling)'],
            array_slice($this->getCells($lines[4]), 0, 4)
        );
        self::assertSame(
            [(string)$done->id, 'media', 'export', 'done', date('Y-m-d H:i:s', 1_700_000_000),
                date('Y-m-d H:i:s', 1_700_000_000), ''],
            $this->getCells($lines[5])
        );
    }

    public function testFinishedJobIsNotCancelling(): void {
        $job = $this->queue->enqueue('a');
        $this->queue->claimNext();
        $this->queue->requestCancel($job->id);
        $this->queue->markCancelled($job->id);
        $retried = $this->queue->enqueue('b');
        $this->queue->claimNext();
        $this->queue->retry($retried->id, 'Error', 0);

        [, $output] = CommandRunner::run(new ListJobsCommand($this->queue));

        $lines = explode("\n", $output->stdout);
        // The 1st attempt of a job waiting for its retry: no attempts count.
        self::assertStringStartsWith('queued (retry at ', $this->getCells($lines[3])[3]);
        self::assertStringEndsWith(')', $this->getCells($lines[3])[3]);
        self::assertSame('cancelled', $this->getCells($lines[4])[3]);
    }

    public function testQueuesAndLimit(): void {
        $this->queue->enqueue('a', [], 'one');
        $this->queue->enqueue('b', [], 'two');
        $this->queue->enqueue('c', [], 'three');
        $this->queue->enqueue('d', [], 'one');

        self::assertSame(['d', 'c', 'b', 'a'], $this->listTypes([]));
        self::assertSame(['d', 'a'], $this->listTypes(['--queue=one']));
        self::assertSame(['d', 'b', 'a'], $this->listTypes(['-q', ' one, ,two ']));
        self::assertSame(['d', 'c'], $this->listTypes(['--limit=2']));
        self::assertSame(['d'], $this->listTypes(['-l', '0']));
        self::assertSame(['d', 'c', 'b', 'a'], $this->listTypes(['--queue=']));
    }

    public function testInvalidLimit(): void {
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('Option --limit must be a non-negative integer.');

        CommandRunner::run(new ListJobsCommand($this->queue), ['--limit=ten']);
    }

    public function testDefaultLimit(): void {
        for ($i = 0; $i < 21; $i++) {
            $this->queue->enqueue('a');
        }

        self::assertCount(20, $this->listTypes([]));
    }

    /**
     * @param list<string> $tokens
     *
     * @return string[] The types of the listed jobs.
     */
    protected function listTypes(array $tokens): array {
        [, $output] = CommandRunner::run(new ListJobsCommand($this->queue), $tokens);
        $lines = array_slice(explode("\n", trim($output->stdout)), 3, -1);

        return array_map(fn(string $line): string => $this->getCells($line)[2], $lines);
    }

    /**
     * @return string[]
     */
    protected function getCells(string $line): array {
        return array_map(trim(...), array_slice(explode('│', $line), 1, -1));
    }

    protected function setTimes(int $time): void {
        $this->storage->getDriver()->execute(
            "UPDATE {$this->storage->getTable()} SET created = :time,"
            . ' finished = CASE WHEN finished IS NULL THEN NULL ELSE :time END',
            ['time' => $time]
        );
    }
}
