<?php

declare(strict_types=1);

namespace Runway\QueueManager\Command;

use Runway\Console\Input\IInput;
use Runway\Console\Output\IOutput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;
use Runway\QueueManager\Storage\IQueueSchema;
use Runway\QueueManager\Storage\IQueueStorage;

class InstallQueueCommand extends AQueueCommand {
    public function __construct(
        protected IQueueSchema  $schema,
        protected IQueueStorage $storage
    ) {}

    public function getName(): string {
        return 'queue:install';
    }

    public function getDescription(): string {
        return 'Create the queue table, unless it exists (SQLite, MySQL / MariaDB)';
    }

    protected function configure(): void {
        $this->addOption(
            'print',
            null,
            ParameterModeEnum::VALUE_NONE,
            'Only show the SQL (the table prefix is not added)'
        );
    }

    protected function execute(IInput $input, IOutput $output): int {
        if ($input->getOption('print')) {
            foreach ($this->schema->getStatements() as $statement) {
                $output->writeln($output->escape("$statement;") . "\n");
            }

            return 0;
        }

        $this->schema->install();
        $output->success(
            'The queue table is ready (' . $this->storage->getDriverName() . ', '
            . ($this->storage->hasOwnDatabase() ? 'QUEUE_DSN' : 'the database of the application') . ').'
        );

        return 0;
    }
}
