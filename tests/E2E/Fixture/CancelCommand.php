<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\E2E\Fixture;

use Runway\Console\Command\ACommand;
use Runway\Console\Input\IInput;
use Runway\Console\Output\IOutput;
use Runway\QueueManager\IJobQueue;

/**
 * Cancels a job as the application does: prints "cancelled" or "finished".
 */
class CancelCommand extends ACommand {
    public function __construct(
        protected IJobQueue $queue
    ) {}

    public function getName(): string {
        return 'test:cancel';
    }

    public function getDescription(): string {
        return 'Cancel a job';
    }

    protected function configure(): void {
        $this->addArgument('id');
    }

    protected function execute(IInput $input, IOutput $output): int {
        $output->writeln($this->queue->requestCancel((int)$input->getArgument('id')) ? 'cancelled' : 'finished');

        return 0;
    }
}
