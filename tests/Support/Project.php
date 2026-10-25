<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Support;

use PDO;
use RuntimeException;

/**
 * A project in a new temporary folder: the "console" script (tests/E2E/Fixture/console.php), its .env with the SQLite
 * database of the application (app.sqlite), the lock files in var/queue. The commands run in separate processes.
 */
final class Project {
    public readonly string $root;

    /** @var list<resource> The processes started in the background, killed when the project is removed. */
    protected array $processes = [];

    /**
     * @param array<string, string> $env Added to (or replacing) the .env values; "{root}" is the project folder.
     */
    public function __construct(array $env = []) {
        // Forward slashes: they work on Windows too, and need no escaping in the .env file.
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/runway-queue-e2e-' . bin2hex(random_bytes(8));

        if (!mkdir($this->root, 0700)) {
            throw new RuntimeException("Cannot create {$this->root}.");
        }

        $fixture = str_replace('\\', '/', dirname(__DIR__) . '/E2E/Fixture/console.php');
        file_put_contents(
            "$this->root/console",
            "<?php\n\ndefine('PROJECT_ROOT', __DIR__);\n\nrequire " . var_export($fixture, true) . ";\n"
        );

        $values = [
            'DSN'                    => "sqlite:$this->root/app.sqlite",
            'QUEUE_DSN'              => '',
            'QUEUE_WORKER_AUTOSTART' => 'true',
            'QUEUE_PHP_BINARY'       => str_replace('\\', '/', PHP_BINARY),
            ...$env,
        ];
        file_put_contents(
            "$this->root/.env",
            implode('', array_map(
                fn(string $name, string $value): string
                    => "$name=\"" . str_replace('{root}', $this->root, $value) . "\"\n",
                array_keys($values),
                $values
            ))
        );
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} The exit code, STDOUT and STDERR (the lines end with "\n").
     */
    public function run(array $arguments): array {
        $output = "$this->root/output-" . bin2hex(random_bytes(4));
        $process = $this->open($arguments, "$output.out", "$output.err");
        $exitCode = proc_close($process);

        $result = [
            $exitCode,
            str_replace("\r\n", "\n", (string)file_get_contents("$output.out")),
            str_replace("\r\n", "\n", (string)file_get_contents("$output.err")),
        ];
        unlink("$output.out");
        unlink("$output.err");

        return $result;
    }

    /**
     * Starts the command in the background (its output is dropped).
     *
     * @param list<string> $arguments
     *
     * @return resource
     */
    public function start(array $arguments) {
        $output = "$this->root/background-" . bin2hex(random_bytes(4));
        $process = $this->open($arguments, "$output.out", "$output.err");
        $this->processes[] = $process;

        return $process;
    }

    /**
     * @param resource $process
     */
    public function kill($process): void {
        proc_terminate($process, 9);
        $this->waitFor(static fn(): bool => !proc_get_status($process)['running'], 'The process is killed.');
    }

    /**
     * @param resource $process
     *
     * @return int The exit code.
     */
    public function waitForExit($process): int {
        $exitCode = -1;
        $this->waitFor(static function () use ($process, &$exitCode): bool {
            $status = proc_get_status($process);
            $exitCode = $status['exitcode'];

            return !$status['running'];
        }, 'The process exits.');

        return $exitCode;
    }

    /**
     * @param callable(): bool $condition
     */
    public function waitFor(callable $condition, string $description, float $timeout = 20.0): void {
        $deadline = microtime(true) + $timeout;

        while (!$condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out: $description");
            }

            usleep(50_000);
        }
    }

    /**
     * @return array<string, mixed>|null The row of the job in the queue table of the application database.
     */
    public function getJob(int $id, string $database = 'app.sqlite'): ?array {
        $statement = $this->connect($database)->prepare('SELECT * FROM jobs WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, int|string> $params
     */
    public function execute(string $query, array $params = [], string $database = 'app.sqlite'): void {
        $this->connect($database)->prepare($query)->execute($params);
    }

    /**
     * @return list<string> The table names of the database.
     */
    public function getTables(string $database): array {
        $statement = $this->connect($database)->query("SELECT name FROM sqlite_master WHERE type = 'table'");

        $names = ($statement !== false) ? $statement->fetchAll(PDO::FETCH_COLUMN) : [];

        return array_values(array_filter($names, is_string(...)));
    }

    public function remove(): void {
        foreach ($this->processes as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }

            proc_close($process);
        }

        $this->processes = [];
        $this->removeFolder($this->root);
    }

    /**
     * @param list<string> $arguments
     *
     * @return resource
     */
    protected function open(array $arguments, string $stdout, string $stderr) {
        $process = proc_open(
            [PHP_BINARY, "$this->root/console", ...$arguments],
            [['pipe', 'r'], ['file', $stdout, 'w'], ['file', $stderr, 'w']],
            $pipes,
            $this->root,
            // No colors: the output is compared as text.
            [...getenv(), 'NO_COLOR' => '1']
        );

        if ($process === false) {
            throw new RuntimeException('The console cannot be started.');
        }

        fclose($pipes[0]);

        return $process;
    }

    protected function connect(string $database): PDO {
        // The workers write at the same time: the reads wait for them.
        return new PDO("sqlite:$this->root/$database", options: [PDO::ATTR_TIMEOUT => 10]);
    }

    protected function removeFolder(string $folder): void {
        foreach (scandir($folder) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = "$folder/$name";
            is_dir($path) && !is_link($path) ? $this->removeFolder($path) : @unlink($path);
        }

        @rmdir($folder);
    }
}
