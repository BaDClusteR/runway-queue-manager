<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\QueueManager\Exception\InvalidQueueNameException;

/**
 * A queue name is a part of a lock file name (see IQueueWorker) and of a command line (see IQueueWorkerLauncher):
 * letters, digits, ".", "_" and "-" only.
 */
final class QueueName {
    protected const string PATTERN = '/^[A-Za-z0-9._-]{1,50}$/';

    /**
     * @throws InvalidQueueNameException
     */
    public static function check(string $queue): string {
        return preg_match(self::PATTERN, $queue)
            ? $queue
            : throw new InvalidQueueNameException(
                "Invalid queue name \"$queue\": 1-50 letters, digits, \".\", \"_\" and \"-\" are allowed."
            );
    }

    /**
     * @param string[] $queues
     *
     * @return string[] Unique, sorted.
     *
     * @throws InvalidQueueNameException
     */
    public static function checkAll(array $queues): array {
        $queues = array_values(array_unique(array_map(self::check(...), $queues)));
        sort($queues);

        return $queues;
    }
}
