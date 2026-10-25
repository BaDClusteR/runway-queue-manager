<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Support;

use Runway\Console\Command\ICommand;
use Runway\Console\Input\Parser\InputParser;

/**
 * Runs a command in the process, the input parsed as the application does it.
 */
final class CommandRunner {
    /**
     * @param list<string> $tokens The command line after the command name, e.g. ["--queue=a,b", "--once"].
     *
     * @return array{int, BufferedOutput} The exit code and the output.
     */
    public static function run(ICommand $command, array $tokens = []): array {
        $output = new BufferedOutput();
        $exitCode = $command->run(new InputParser()->parse($tokens, $command->getParameters()), $output);

        return [$exitCode, $output];
    }
}
