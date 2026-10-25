<?php

declare(strict_types=1);

namespace Runway\QueueManager\Command;

use Runway\Console\Command\ACommand;
use Runway\Console\Exception\InvalidParameterException;
use Runway\Console\Input\IInput;
use Runway\Console\Parameter\Enum\ParameterModeEnum;

abstract class AQueueCommand extends ACommand {
    public function getGroup(): ?string {
        return 'Queue';
    }

    protected function addQueueOption(string $description): static {
        return $this->addOption('queue', 'q', ParameterModeEnum::VALUE_REQUIRED, $description);
    }

    /**
     * @return string[]|null The comma-separated queues of the option, null if it's not set. The names are checked by
     *                       the queue (see QueueName).
     */
    protected function getQueues(IInput $input): ?array {
        $value = $input->getOption('queue');

        if ($value === null || $value === '') {
            return null;
        }

        return array_values(
            array_filter(array_map(trim(...), explode(',', $value)), static fn(string $queue): bool => $queue !== '')
        );
    }

    /**
     * A typo must not become 0 (e.g. "--days=thirty" of queue:prune would delete all the finished jobs).
     *
     * @throws InvalidParameterException If the value is not a non-negative integer.
     */
    protected function getNonNegativeIntOption(IInput $input, string $name, int $default): int {
        $value = $input->getOption($name);

        if ($value === null) {
            return $default;
        }

        if (!preg_match('/^\d{1,9}$/', trim($value))) {
            throw new InvalidParameterException("Option --$name must be a non-negative integer.");
        }

        return (int)$value;
    }
}
