<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\Logger\ILogger;
use Runway\QueueManager\Exception\InvalidQueueNameException;

/**
 * Unix only (a shell running the command in the background): on Windows, the jobs wait for the scheduled
 * "queue:work" task.
 */
class QueueWorkerLauncher implements IQueueWorkerLauncher {
    /**
     * @param mixed       $enabled     QUEUE_WORKER_AUTOSTART: false - the worker is started by cron only (see the
     *                                 "queue:work" command). Enabled if not set.
     * @param mixed       $phpBinary   QUEUE_PHP_BINARY: the PHP CLI binary. By default, the one of the PHP installation
     *                                 running the web server (PHP-FPM is in the same "bin" folder as the CLI one).
     * @param string|null $consolePath The console script of the project. Empty: "console" in PROJECT_ROOT, or in the
     *                                 current folder.
     */
    public function __construct(
        protected ILogger $logger,
        protected mixed   $enabled = true,
        protected mixed   $phpBinary = null,
        protected ?string $consolePath = null
    ) {}

    public function launch(?string $queue = null): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->isWindows()) {
            $this->logger->warning('The queue worker cannot be started on Windows: run "queue:work" by a schedule.');

            return false;
        }

        if (!function_exists('exec')) {
            $this->logger->warning('The queue worker cannot be started: exec() is disabled.');

            return false;
        }

        $command = $this->getCommand($queue);
        $exitCode = $this->execute($command);

        if ($exitCode !== 0) {
            $this->logger->warning("The queue worker cannot be started: \"$command\" exited with code $exitCode.");
        }

        return $exitCode === 0;
    }

    protected function isEnabled(): bool {
        // The ENV values come typed: a boolean, or a string (e.g. "off"); empty - not set.
        if ($this->enabled === null || $this->enabled === '' || !is_scalar($this->enabled)) {
            return true;
        }

        return filter_var($this->enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * "&": the request does not wait for the worker; the output is redirected, so the worker does not hold the output
     * of the web server process.
     *
     * @throws InvalidQueueNameException
     */
    protected function getCommand(?string $queue): string {
        $phpBinary = is_scalar($this->phpBinary) ? (string)$this->phpBinary : '';

        return sprintf(
            '%s %s queue:work%s > /dev/null 2>&1 &',
            escapeshellarg(($phpBinary !== '') ? $phpBinary : PHP_BINDIR . '/php'),
            escapeshellarg($this->getConsolePath()),
            ($queue !== null) ? ' --queue=' . escapeshellarg(QueueName::check($queue)) : ''
        );
    }

    /**
     * @return int The exit code of the shell (not of the worker: it runs in the background).
     */
    protected function execute(string $command): int {
        exec($command, $output, $exitCode);

        return $exitCode;
    }

    protected function isWindows(): bool {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function getConsolePath(): string {
        if ((string)$this->consolePath !== '') {
            return (string)$this->consolePath;
        }

        $root = defined('PROJECT_ROOT') ? constant('PROJECT_ROOT') : getcwd();

        return (is_string($root) ? $root : '.') . '/console';
    }
}
