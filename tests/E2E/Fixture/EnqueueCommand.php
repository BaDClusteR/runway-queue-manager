<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\E2E\Fixture;

use Runway\Console\Command\ACommand;
use Runway\Console\Input\IInput;
use Runway\Console\Output\IOutput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;
use Runway\QueueManager\IJobQueue;
use Runway\QueueManager\IQueueWorkerLauncher;

/**
 * Adds a job as the application does (e.g. a web request): prints its ID, and "launched" if the worker is started.
 */
class EnqueueCommand extends ACommand {
    public function __construct(
        protected IJobQueue            $queue,
        protected IQueueWorkerLauncher $launcher
    ) {}

    public function getName(): string {
        return 'test:enqueue';
    }

    public function getDescription(): string {
        return 'Add a job';
    }

    protected function configure(): void {
        $this->addArgument('type')
             ->addOption('payload', null, ParameterModeEnum::VALUE_REQUIRED, 'JSON', '{}')
             ->addOption('queue', null, ParameterModeEnum::VALUE_REQUIRED)
             ->addOption('launch', null, ParameterModeEnum::VALUE_NONE);
    }

    protected function execute(IInput $input, IOutput $output): int {
        $payload = json_decode((string)$input->getOption('payload'), true, flags: JSON_THROW_ON_ERROR);
        $queue = $input->getOption('queue');
        $job = $this->queue->enqueue((string)$input->getArgument('type'), is_array($payload) ? $payload : [], $queue);

        $output->writeln((string)$job->id);

        if ($input->getOption('launch')) {
            $output->writeln($this->launcher->launch($queue) ? 'launched' : 'not launched');
        }

        return 0;
    }
}
