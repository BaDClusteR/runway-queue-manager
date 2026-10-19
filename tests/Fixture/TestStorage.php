<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Fixture;

use Runway\Env\Provider\IEnvVariablesProvider;
use Runway\Event\IEventDispatcher;
use Runway\QueueManager\Storage\QueueSchema;
use Runway\QueueManager\Storage\QueueStorage;
use Runway\Singleton\Container;

/**
 * The queue storages of the tests: SQLite in memory, and MySQL / MariaDB if QUEUE_TEST_MYSQL_DSN is set (e.g.
 * "mysql:unix_socket=/tmp/mysql.sock;dbname=queue_test", with QUEUE_TEST_MYSQL_USER and QUEUE_TEST_MYSQL_PASSWORD).
 * The table is created empty.
 */
final class TestStorage {
    public static function sqlite(string $table = 'jobs', string $prefix = ''): QueueStorage {
        return self::create('sqlite::memory:', null, null, $table, $prefix);
    }

    public static function mysql(string $table = 'jobs', string $prefix = 'test_'): ?QueueStorage {
        $dsn = (string)getenv('QUEUE_TEST_MYSQL_DSN');

        if ($dsn === '') {
            return null;
        }

        $storage = self::create(
            $dsn,
            (string)getenv('QUEUE_TEST_MYSQL_USER') ?: null,
            (string)getenv('QUEUE_TEST_MYSQL_PASSWORD') ?: null,
            $table,
            $prefix,
            isInstalled: false
        );
        $storage->getDriver()->execute("DROP TABLE IF EXISTS {$storage->getTable()}");
        new QueueSchema($storage)->install();

        return $storage;
    }

    protected static function create(
        string  $dsn,
        ?string $user,
        ?string $password,
        string  $table,
        string  $prefix,
        bool    $isInstalled = true
    ): QueueStorage {
        $container = Container::getInstance();
        $storage = new QueueStorage(
            $container->getService(IEnvVariablesProvider::class),
            $container->getService(IEventDispatcher::class),
            $dsn,
            $user,
            $password,
            $prefix,
            $table
        );

        if ($isInstalled) {
            new QueueSchema($storage)->install();
        }

        return $storage;
    }
}
