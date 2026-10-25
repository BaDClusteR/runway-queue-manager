<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Runway\Console\Command\ICommand;
use Runway\QueueManager\Command\InstallQueueCommand;
use Runway\QueueManager\Command\ListJobsCommand;
use Runway\QueueManager\Command\PruneQueueCommand;
use Runway\QueueManager\Command\WorkQueueCommand;
use Runway\QueueManager\Handler\IJobHandlerProvider;
use Runway\QueueManager\Handler\JobHandlerProvider;
use Runway\QueueManager\IJobQueue;
use Runway\QueueManager\IQueuePruner;
use Runway\QueueManager\IQueueWorker;
use Runway\QueueManager\IQueueWorkerLauncher;
use Runway\QueueManager\JobQueue;
use Runway\QueueManager\QueuePruner;
use Runway\QueueManager\QueueWorker;
use Runway\QueueManager\QueueWorkerLauncher;
use Runway\QueueManager\Storage\IQueueSchema;
use Runway\QueueManager\Storage\IQueueStorage;
use Runway\QueueManager\Storage\QueueSchema;
use Runway\QueueManager\Storage\QueueStorage;
use Runway\QueueManager\Tests\Fixture\EchoJobHandler;
use Runway\QueueManager\Tests\Fixture\SecretJobHandler;
use Runway\Singleton\Container;

/**
 * The services of config/services.yaml and config/commands.yaml as a project gets them.
 */
class ContainerTest extends TestCase {
    public function testServices(): void {
        $container = Container::getInstance();

        self::assertInstanceOf(JobQueue::class, $container->getService(IJobQueue::class));
        self::assertInstanceOf(QueueWorker::class, $container->getService(IQueueWorker::class));
        self::assertInstanceOf(QueuePruner::class, $container->getService(IQueuePruner::class));
        self::assertInstanceOf(QueueWorkerLauncher::class, $container->getService(IQueueWorkerLauncher::class));
        self::assertInstanceOf(QueueStorage::class, $container->getService(IQueueStorage::class));
        self::assertInstanceOf(QueueSchema::class, $container->getService(IQueueSchema::class));
        self::assertInstanceOf(JobHandlerProvider::class, $container->getService(IJobHandlerProvider::class));
    }

    public function testCommands(): void {
        $commands = [];

        foreach (Container::getInstance()->getServicesByTag('console.command') as $service) {
            if ($service instanceof ICommand) {
                $commands[$service->getName()] = $service::class;
            }
        }

        self::assertSame(InstallQueueCommand::class, $commands['queue:install'] ?? null);
        self::assertSame(ListJobsCommand::class, $commands['queue:list'] ?? null);
        self::assertSame(WorkQueueCommand::class, $commands['queue:work'] ?? null);
        self::assertSame(PruneQueueCommand::class, $commands['queue:prune'] ?? null);
    }

    /**
     * The handlers are the services with the "queue.job_handler" tag (tests/config/services.yaml).
     */
    public function testTaggedHandlers(): void {
        $provider = Container::getInstance()->getService(IJobHandlerProvider::class);

        self::assertInstanceOf(EchoJobHandler::class, $provider->getHandler('echo'));
        self::assertInstanceOf(SecretJobHandler::class, $provider->getHandler('secret'));
        self::assertNull($provider->getHandler('unknown'));
    }

    /**
     * QUEUE_DSN is empty (tests/config/test.env): the queue uses the database of the application.
     */
    public function testStorageOfApplication(): void {
        $container = Container::getInstance();
        $storage = $container->getService(IQueueStorage::class);

        self::assertFalse($storage->hasOwnDatabase());
        self::assertSame($container->getDataStorageDriver(), $storage->getDriver());
        self::assertSame('sqlite', $storage->getDriverName());
        // The queue_table parameter.
        self::assertSame('`{jobs}`', $storage->getTable());
    }

    /**
     * QUEUE_WORKER_AUTOSTART=false (tests/config/test.env).
     */
    public function testLauncherIsDisabledByEnv(): void {
        self::assertFalse(Container::getInstance()->getService(IQueueWorkerLauncher::class)->launch());
    }
}
