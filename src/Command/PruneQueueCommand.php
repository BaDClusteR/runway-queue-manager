<?php

declare(strict_types=1);

namespace Runway\QueueManager\Command;

use Runway\Console\Input\IInput;
use Runway\Console\Output\IOutput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;
use Runway\QueueManager\IQueuePruner;

/**
 * Run it by cron, e.g. daily:
 *
 *     30 4 * * * php /path/to/console queue:prune > /dev/null 2>&1
 */
class PruneQueueCommand extends AQueueCommand {
    protected const int DEFAULT_DAYS = 30;

    public function __construct(
        protected IQueuePruner $pruner
    ) {}

    public function getName(): string {
        return 'queue:prune';
    }

    public function getDescription(): string {
        return 'Delete the finished jobs (done, failed, cancelled) older than the days, with what they have made';
    }

    protected function configure(): void {
        $this->addOption(
            'days',
            'd',
            ParameterModeEnum::VALUE_REQUIRED,
            'Keep the jobs finished in these last days',
            (string)static::DEFAULT_DAYS
        );
    }

    protected function execute(IInput $input, IOutput $output): int {
        $days = max(0, (int)($input->getOption('days') ?? static::DEFAULT_DAYS));
        $count = $this->pruner->prune(time() - $days * 86400);

        $output->success("Jobs deleted: $count.");

        return 0;
    }
}
