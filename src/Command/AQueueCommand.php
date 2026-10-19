<?php

declare(strict_types=1);

namespace Runway\QueueManager\Command;

use Runway\Console\Command\ACommand;
use Runway\Console\Input\IInput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;
use Runway\QueueManager\Exception\InvalidQueueNameException;

abstract class AQueueCommand extends ACommand {
    public function getGroup(): ?string {
        return 'Queue';
    }

    protected function addQueueOption(string $description): static {
        return $this->addOption('queue', 'q', ParameterModeEnum::VALUE_REQUIRED, $description);
    }

    /**
     * @return string[]|null The comma-separated queues of the option, null if it's not set.
     *
     * @throws InvalidQueueNameException
     */
    protected function getQueues(IInput $input): ?array {
        $value = $input->getOption('queue');

        if ($value === null || $value === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', (string)$value)), 'strlen'));
    }
}
