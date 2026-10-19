<?php

declare(strict_types=1);

namespace Runway\QueueManager;

use Runway\Logger\ILogger;

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
        if (!(filter_var($this->enabled ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true)) {
            return false;
        }

        if (!function_exists('exec')) {
            $this->logger->warning('The queue worker cannot be started: exec() is disabled.');

            return false;
        }

        // "&": the request does not wait for the worker; the output is redirected, so the worker does not hold the
        // output of the web server process.
        $command = sprintf(
            '%s %s queue:work%s > /dev/null 2>&1 &',
            escapeshellarg(((string)$this->phpBinary !== '') ? (string)$this->phpBinary : PHP_BINDIR . '/php'),
            escapeshellarg($this->getConsolePath()),
            ($queue !== null) ? ' --queue=' . escapeshellarg(QueueName::check($queue)) : ''
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->warning("The queue worker cannot be started: \"$command\" exited with code $exitCode.");
        }

        return $exitCode === 0;
    }

    protected function getConsolePath(): string {
        if ((string)$this->consolePath !== '') {
            return (string)$this->consolePath;
        }

        return (defined('PROJECT_ROOT') ? (string)constant('PROJECT_ROOT') : (string)getcwd()) . '/console';
    }
}
