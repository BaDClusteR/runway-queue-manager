<?php

declare(strict_types=1);

namespace Runway\QueueManager\Command;

use Runway\Console\Input\IInput;
use Runway\Console\Output\IOutput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\IJobQueue;

class ListJobsCommand extends AQueueCommand {
    public function __construct(
        protected IJobQueue $queue
    ) {}

    public function getName(): string {
        return 'queue:list';
    }

    public function getDescription(): string {
        return 'Show the recent jobs with their statuses';
    }

    protected function configure(): void {
        $this->addQueueOption('Only the jobs of these queues, comma-separated (all the queues if not set)')
             ->addOption('limit', 'l', ParameterModeEnum::VALUE_REQUIRED, 'How many jobs to show', '20');
    }

    protected function execute(IInput $input, IOutput $output): int {
        $jobs = $this->queue->list(max(1, (int)$input->getOption('limit')), $this->getQueues($input));

        if (!$jobs) {
            $output->info('There are no jobs.');

            return 0;
        }

        $output->table(
            ['ID', 'Queue', 'Type', 'Status', 'Created', 'Finished', 'Error'],
            array_map(
                static fn(JobDTO $job): array => [
                    (string)$job->id,
                    $output->escape($job->queue),
                    $output->escape($job->type),
                    $job->status->value
                        . (($job->isCancelRequested && !$job->status->isFinished()) ? ' (cancelling)' : ''),
                    date('Y-m-d H:i:s', $job->created),
                    ($job->finished !== null) ? date('Y-m-d H:i:s', $job->finished) : '',
                    $output->escape((string)$job->error),
                ],
                $jobs
            )
        );

        return 0;
    }
}
