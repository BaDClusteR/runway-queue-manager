<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\Handler\JobHandlerProvider;
use Runway\QueueManager\Tests\Fixture\EchoJobHandler;
use Runway\QueueManager\Tests\Fixture\SecretJobHandler;
use Runway\QueueManager\Tests\Fixture\TestHandler;

class JobHandlerProviderTest extends TestCase {
    public function testHandlerOfType(): void {
        $first = new TestHandler('a', static fn(): array => []);
        $second = new TestHandler('b', static fn(): array => []);
        $duplicate = new TestHandler('a', static fn(): array => []);
        $provider = new JobHandlerProvider([$first, $second, $duplicate]);

        self::assertSame($first, $provider->getHandler('a'));
        self::assertSame($second, $provider->getHandler('b'));
        self::assertNull($provider->getHandler('c'));
        self::assertNull($provider->getHandler('A'));
    }

    public function testTaggedServicesByDefault(): void {
        // The handlers of tests/config/services.yaml.
        self::assertInstanceOf(EchoJobHandler::class, new JobHandlerProvider()->getHandler('echo'));
        self::assertInstanceOf(SecretJobHandler::class, new JobHandlerProvider()->getHandler('secret'));
        self::assertNull(new JobHandlerProvider([])->getHandler('echo'));
    }

    public function testDefaultsOfAbstractHandler(): void {
        $handler = new EchoJobHandler();
        $job = new JobDTO(1, 'main', 'echo', JobStatus::DONE, [], null, null, null, false, 0, null, null);

        self::assertSame([], $handler->getSecretPayloadKeys());
        $handler->cleanUp($job);
        self::assertSame(0, $job->attempts);
        self::assertSame(0, $job->available);
    }
}
