<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Runway\DataStorage\PDO;
use Runway\Env\Provider\IEnvVariablesProvider;
use Runway\Event\IEventDispatcher;
use Runway\QueueManager\Storage\QueueStorage;
use Runway\Singleton\Container;

class QueueStorageTest extends TestCase {
    public function testOwnDatabase(): void {
        $storage = $this->createStorage('sqlite::memory:', tablePrefix: 'q_', table: 'tasks');
        $driver = $storage->getDriver();

        self::assertTrue($storage->hasOwnDatabase());
        self::assertSame('sqlite', $storage->getDriverName());
        self::assertSame('`{tasks}`', $storage->getTable());
        self::assertInstanceOf(PDO::class, $driver);
        self::assertNotSame(Container::getInstance()->getDataStorageDriver(), $driver);
        // Connected once.
        self::assertSame($driver, $storage->getDriver());

        // The prefix is the one of the own database.
        $driver->execute('CREATE TABLE `{tasks}` (id INTEGER)');
        self::assertSame(
            [['name' => 'q_tasks']],
            $driver->getResult("SELECT name FROM sqlite_master WHERE type = 'table'")
        );
    }

    public function testDatabaseOfApplication(): void {
        foreach ([null, '', '  ', false] as $dsn) {
            $storage = $this->createStorage($dsn);

            self::assertFalse($storage->hasOwnDatabase());
            self::assertSame(Container::getInstance()->getDataStorageDriver(), $storage->getDriver());
            // The DSN of the application (tests/config/test.env).
            self::assertSame('sqlite', $storage->getDriverName());
        }
    }

    public function testDriverNameIsLowerCase(): void {
        self::assertSame('mysql', $this->createStorage('MySQL:host=127.0.0.1;dbname=app')->getDriverName());
        self::assertSame('', $this->createStorage('nonsense')->getDriverName());
    }

    public function testDefaultTable(): void {
        self::assertSame('`{jobs}`', $this->createStorage()->getTable());
    }

    /**
     * The ENV values come typed: e.g. a numeric password is an integer. The credentials go to PDO as strings: a wrong
     * password of SQLite is not checked, so the connection of a typed one does not fail. A value that is not a scalar
     * is empty.
     */
    public function testTypedEnvValues(): void {
        $storage = $this->createStorage('sqlite::memory:', 12345, 12345.5, ['p_']);

        $storage->getDriver()->execute('CREATE TABLE `{jobs}` (id INTEGER)');

        self::assertSame(
            [['name' => 'jobs']],
            $storage->getDriver()->getResult("SELECT name FROM sqlite_master WHERE type = 'table'")
        );
        self::assertFalse($this->createStorage(['sqlite::memory:'])->hasOwnDatabase());
    }

    protected function createStorage(
        mixed  $dsn = null,
        mixed  $user = null,
        mixed  $password = null,
        mixed  $tablePrefix = null,
        string $table = 'jobs'
    ): QueueStorage {
        $container = Container::getInstance();

        return new QueueStorage(
            $container->getService(IEnvVariablesProvider::class),
            $container->getService(IEventDispatcher::class),
            $dsn,
            $user,
            $password,
            $tablePrefix,
            $table
        );
    }
}
