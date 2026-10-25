<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Command\WorkQueueCommand;
use Runway\QueueManager\IQueueWorker;
use Runway\QueueManager\Tests\Support\CommandRunner;

class WorkQueueCommandTest extends TestCase {
    /** @var list<array{bool, string[]|null}> The arguments of the runs: once, queues. */
    protected array $runs = [];

    protected ?int $jobsCount = 2;

    protected string $memoryLimit;

    protected function setUp(): void {
        $this->memoryLimit = (string)ini_get('memory_limit');
    }

    protected function tearDown(): void {
        ini_set('memory_limit', $this->memoryLimit);
    }

    public function testDefinition(): void {
        $command = new WorkQueueCommand($this->createWorker());

        self::assertSame('queue:work', $command->getName());
        self::assertSame('Run the queued jobs until there are none', $command->getDescription());
        self::assertSame('Queue', $command->getGroup());
    }

    public function testRun(): void {
        ini_set('memory_limit', '256M');

        [$exitCode, $output] = CommandRunner::run(new WorkQueueCommand($this->createWorker()));

        self::assertSame(0, $exitCode);
        self::assertSame([[false, null]], $this->runs);
        // The events are escaped.
        self::assertSame("Job #1 (<b>, main): done.\nJobs run: 2.\n", $output->stdout);
        self::assertSame('-1', ini_get('memory_limit'));
    }

    public function testOptions(): void {
        CommandRunner::run(new WorkQueueCommand($this->createWorker()), ['--once', '--queue=main, ,media']);
        CommandRunner::run(new WorkQueueCommand($this->createWorker()), ['-q', 'media']);

        self::assertSame([[true, ['main', 'media']], [false, ['media']]], $this->runs);
    }

    public function testAnotherWorkerIsRunning(): void {
        $this->jobsCount = null;

        [$exitCode, $output] = CommandRunner::run(new WorkQueueCommand($this->createWorker()));

        self::assertSame(0, $exitCode);
        self::assertSame(
            "Job #1 (<b>, main): done.\nAnother worker is running: it will run the queued jobs.\n",
            $output->stdout
        );
    }

    protected function createWorker(): IQueueWorker {
        return new class($this) implements IQueueWorker {
            public function __construct(
                protected WorkQueueCommandTest $test
            ) {}

            public function run(?callable $onEvent = null, bool $once = false, ?array $queues = null): ?int {
                return $this->test->recordRun($onEvent, $once, $queues);
            }

            public function isRunning(?array $queues = null): bool {
                return false;
            }
        };
    }

    /**
     * @param string[]|null $queues
     */
    public function recordRun(?callable $onEvent, bool $once, ?array $queues): ?int {
        $this->runs[] = [$once, $queues];

        if ($onEvent) {
            $onEvent('Job #1 (<b>, main): done.');
        }

        return $this->jobsCount;
    }
}
