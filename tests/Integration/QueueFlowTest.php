<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Runway\Console\Command\ICommand;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\IJobQueue;
use Runway\QueueManager\IQueuePruner;
use Runway\QueueManager\IQueueWorker;
use Runway\QueueManager\Storage\IQueueSchema;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Tests\Support\BufferedOutput;
use Runway\QueueManager\Tests\Support\CommandRunner;
use Runway\QueueManager\Tests\Support\JobAssertions;
use Runway\Singleton\Container;

/**
 * The jobs from the services of the container: the database of the application (SQLite in memory), the tagged
 * handlers, the lock files in PROJECT_ROOT/var/queue.
 */
class QueueFlowTest extends TestCase {
    use JobAssertions;

    protected IJobQueue $queue;

    protected function setUp(): void {
        $container = Container::getInstance();
        $storage = $container->getService(IQueueStorage::class);
        $storage->getDriver()->execute("DROP TABLE IF EXISTS {$storage->getTable()}");
        $container->getService(IQueueSchema::class)->install();
        $this->queue = $container->getService(IJobQueue::class);
    }

    public function testJobIsRun(): void {
        $job = $this->queue->enqueue('echo', ['name' => 'Тест']);

        self::assertSame(1, Container::getInstance()->getService(IQueueWorker::class)->run());

        $done = $this->queue->get($job->id);
        self::assertSame(JobStatus::DONE, $done?->status);
        self::assertSame(['name' => 'Тест'], $done->result);
        self::assertSame(['stage' => 'echo'], $done->progress);
        self::assertFileExists(PROJECT_ROOT . '/var/queue/main.lock');
    }

    public function testSecretsAreRemoved(): void {
        $run = $this->queue->enqueue('secret', ['password' => 'p', 'name' => 'x']);
        $cancelled = $this->queue->enqueue('secret', ['password' => 'p']);

        // The handlers of the queue service: the secrets of a job that will not run are removed at once.
        self::assertTrue($this->queue->requestCancel($cancelled->id));
        Container::getInstance()->getService(IQueueWorker::class)->run();

        self::assertSame(['hasPassword' => true], self::found($this->queue->get($run->id))->result);
        self::assertSame(['name' => 'x'], self::found($this->queue->get($run->id))->payload);
        self::assertSame([], self::found($this->queue->get($cancelled->id))->payload);
    }

    public function testPrune(): void {
        $job = $this->queue->enqueue('echo');
        Container::getInstance()->getService(IQueueWorker::class)->run();

        self::assertSame(0, Container::getInstance()->getService(IQueuePruner::class)->prune(time() - 60));
        self::assertSame(1, Container::getInstance()->getService(IQueuePruner::class)->prune(time() + 1));
        self::assertNull($this->queue->get($job->id));
    }

    public function testCommands(): void {
        [$exitCode, $output] = $this->runCommand('queue:install', ['--print']);
        self::assertSame(0, $exitCode);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `{jobs}` (', $output->stdout);

        [, $output] = $this->runCommand('queue:install');
        self::assertSame("The queue table is ready (sqlite, the database of the application).\n", $output->stdout);

        $job = $this->queue->enqueue('echo', [], 'media');
        $this->queue->enqueue('unknown');

        [$exitCode, $output] = $this->runCommand('queue:work', ['--queue=media']);
        self::assertSame(0, $exitCode);
        self::assertSame(
            "Job #$job->id (echo, media): started.\nJob #$job->id (echo, media): done.\nJobs run: 1.\n",
            $output->stdout
        );

        [, $output] = $this->runCommand('queue:list', ['--queue=media']);
        self::assertStringContainsString('│ done ', $output->stdout);
        self::assertStringNotContainsString('unknown', $output->stdout);

        $this->runCommand('queue:work');
        [, $output] = $this->runCommand('queue:prune', ['--days=0']);
        // Finished in this second: not before it.
        self::assertSame("Jobs deleted: 0.\n", $output->stdout);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{int, BufferedOutput}
     */
    protected function runCommand(string $name, array $tokens = []): array {
        foreach (Container::getInstance()->getServicesByTag('console.command') as $command) {
            if ($command instanceof ICommand && $command->getName() === $name) {
                return CommandRunner::run($command, $tokens);
            }
        }

        self::fail("No command $name.");
    }
}
