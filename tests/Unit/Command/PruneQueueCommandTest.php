<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Runway\Console\Exception\InvalidParameterException;
use Runway\Console\Parameter\DTO\ParameterDTO;
use Runway\QueueManager\Command\PruneQueueCommand;
use Runway\QueueManager\IQueuePruner;
use Runway\QueueManager\Tests\Support\CommandRunner;

class PruneQueueCommandTest extends TestCase {
    /** @var int[] The "finished before" times of the prunes. */
    public array $prunes = [];

    public function testDefinition(): void {
        $command = new PruneQueueCommand($this->createPruner());

        self::assertSame('queue:prune', $command->getName());
        self::assertSame(
            'Delete the finished jobs (done, failed, cancelled) older than the days, with what they have made',
            $command->getDescription()
        );
        self::assertSame('Queue', $command->getGroup());
        self::assertSame(
            [['days', 'd', '30', 'Keep the jobs finished in these last days']],
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

    public function testDays(): void {
        $this->assertPrunedBefore(30 * 86400, []);
        $this->assertPrunedBefore(7 * 86400, ['--days=7']);
        $this->assertPrunedBefore(86400, ['-d', ' 1 ']);
        $this->assertPrunedBefore(0, ['--days=0']);
    }

    public function testOutput(): void {
        [$exitCode, $output] = CommandRunner::run(new PruneQueueCommand($this->createPruner()));

        self::assertSame(0, $exitCode);
        self::assertSame("Jobs deleted: 3.\n", $output->stdout);
    }

    /**
     * A typo is not 0 days: all the finished jobs would be deleted.
     */
    public function testInvalidDays(): void {
        foreach (['thirty', '-1', '3O', '1.5', '', '1e3', '9999999999', "1\n2"] as $days) {
            try {
                CommandRunner::run(new PruneQueueCommand($this->createPruner()), ["--days=$days"]);
                self::fail("\"$days\" is not valid.");
            } catch (InvalidParameterException $e) {
                self::assertSame('Option --days must be a non-negative integer.', $e->getMessage());
            }
        }

        self::assertSame([], $this->prunes);
    }

    /**
     * @param list<string> $tokens
     */
    protected function assertPrunedBefore(int $age, array $tokens): void {
        $this->prunes = [];
        $before = time();
        CommandRunner::run(new PruneQueueCommand($this->createPruner()), $tokens);
        $after = time();

        [$finishedBefore] = $this->getPrunes();
        self::assertCount(1, $this->getPrunes());
        self::assertGreaterThanOrEqual($before - $age, $finishedBefore);
        self::assertLessThanOrEqual($after - $age, $finishedBefore);
    }

    /**
     * @return int[]
     */
    protected function getPrunes(): array {
        return $this->prunes;
    }

    protected function createPruner(): IQueuePruner {
        return new class($this) implements IQueuePruner {
            public function __construct(
                protected PruneQueueCommandTest $test
            ) {}

            public function prune(int $finishedBefore): int {
                $this->test->prunes[] = $finishedBefore;

                return 3;
            }
        };
    }
}
