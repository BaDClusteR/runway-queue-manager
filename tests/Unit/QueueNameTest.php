<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Runway\QueueManager\Exception\InvalidQueueNameException;
use Runway\QueueManager\QueueName;

class QueueNameTest extends TestCase {
    /**
     * @return array<string, array{string}>
     */
    public static function validProvider(): array {
        return [
            'letters'     => ['main'],
            'all chars'   => ['Media.thumbs_2-x'],
            'one char'    => ['a'],
            'dots only'   => ['..'],
            '50 chars'    => [str_repeat('q', 50)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array {
        return [
            'empty'         => [''],
            '51 chars'      => [str_repeat('q', 51)],
            'slash'         => ['../main'],
            'backslash'     => ['a\\b'],
            'space'         => ['a b'],
            'shell'         => ['a;rm'],
            'new line'      => ["main\n"],
            'leading space' => [' main'],
            'non-ASCII'     => ['очередь'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testValid(string $queue): void {
        self::assertSame($queue, QueueName::check($queue));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(string $queue): void {
        $this->expectException(InvalidQueueNameException::class);
        $this->expectExceptionMessage(
            "Invalid queue name \"$queue\": 1-50 letters, digits, \".\", \"_\" and \"-\" are allowed."
        );

        QueueName::check($queue);
    }

    public function testCheckAllMakesUniqueSortedList(): void {
        self::assertSame(['a', 'b', 'main'], QueueName::checkAll(['main', 'b', 'main', 'a', 'b']));
        self::assertSame([], QueueName::checkAll([]));
        self::assertSame(['x'], QueueName::checkAll([5 => 'x']));
    }

    public function testCheckAllChecksEveryName(): void {
        $this->expectException(InvalidQueueNameException::class);

        QueueName::checkAll(['main', 'a/b']);
    }
}
