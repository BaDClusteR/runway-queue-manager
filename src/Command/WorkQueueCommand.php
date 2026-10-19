<?php

declare(strict_types=1);

namespace Runway\QueueManager\Command;

use Runway\Console\Input\IInput;
use Runway\Console\Output\IOutput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;
use Runway\QueueManager\IQueueWorker;

/**
 * Started in the background when a job is added (see IQueueWorkerLauncher). For a server where the launch is impossible
 * or disabled, run it by cron, e.g. every minute (a second worker of a queue exits at once):
 *
 *     * * * * * php /path/to/console queue:work > /dev/null 2>&1
 */
class WorkQueueCommand extends AQueueCommand {
    public function __construct(
        protected IQueueWorker $worker
    ) {}

    public function getName(): string {
        return 'queue:work';
    }

    public function getDescription(): string {
        return 'Run the queued jobs until there are none';
    }

    protected function configure(): void {
        $this->addQueueOption('The queues to run, comma-separated (the default one if not set)')
             ->addOption('once', null, ParameterModeEnum::VALUE_NONE, 'Run one job only');
    }

    protected function execute(IInput $input, IOutput $output): int {
        // The jobs can be long and big (e.g. an export), and there is no time limit in CLI anyway.
        ini_set('memory_limit', '-1');

        $jobsCount = $this->worker->run(
            static fn(string $message) => $output->writeln($output->escape($message)),
            (bool)$input->getOption('once'),
            $this->getQueues($input)
        );

        if ($jobsCount === null) {
            $output->info('Another worker is running: it will run the queued jobs.');
        } else {
            $output->success("Jobs run: $jobsCount.");
        }

        return 0;
    }
}
