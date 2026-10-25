<?php

declare(strict_types=1);

namespace Runway\QueueManager\Command;

use Runway\Console\Input\IInput;
use Runway\Console\Output\IOutput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;
use Runway\QueueManager\DTO\JobDTO;
use Runway\QueueManager\Enum\JobStatus;
use Runway\QueueManager\IJobQueue;

class ListJobsCommand extends AQueueCommand {
    protected const int DEFAULT_LIMIT = 20;

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
             ->addOption(
                 'limit',
                 'l',
                 ParameterModeEnum::VALUE_REQUIRED,
                 'How many jobs to show',
                 (string)static::DEFAULT_LIMIT
             );
    }

    protected function execute(IInput $input, IOutput $output): int {
        $jobs = $this->queue->list(
            // 0 is 1 (see IJobQueue::list()).
            $this->getNonNegativeIntOption($input, 'limit', static::DEFAULT_LIMIT),
            $this->getQueues($input)
        );

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
                        . (($job->isCancelRequested && !$job->status->isFinished()) ? ' (cancelling)' : '')
                        // A job waiting for its retry: the error is of the failed attempt.
                        . (($job->status === JobStatus::QUEUED && $job->attempts)
                            ? ' (retry at ' . date('H:i:s', $job->available) . ')'
                            : '')
                        . (($job->attempts > 1) ? ", $job->attempts attempts" : ''),
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
