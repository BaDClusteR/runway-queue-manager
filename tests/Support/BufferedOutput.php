<?php

declare(strict_types=1);

namespace Runway\QueueManager\Tests\Support;

use LogicException;
use Runway\Console\Output\Formatter\IOutputFormatter;
use Runway\Console\Output\Formatter\OutputFormatter;
use Runway\Console\Output\IOutput;
use Runway\Console\Output\ProgressBar\IProgressBar;
use Runway\Console\Output\Table\Table;

/**
 * Collects the output (without the formatting) instead of writing it: STDOUT and STDERR separately. The lines end with
 * "\n" on every platform. The commands of the queue have no prompts and no progress bars.
 */
class BufferedOutput implements IOutput {
    public string $stdout = '';

    public string $stderr = '';

    public function __construct(
        protected IOutputFormatter $formatter = new OutputFormatter()
    ) {}

    public function write(string $message): void {
        $this->stdout .= $this->formatter->format(str_replace(PHP_EOL, "\n", $message), false);
    }

    public function writeln(string $message): void {
        $this->write($message . PHP_EOL);
    }

    public function success(string $message): void {
        $this->writeln($message);
    }

    public function error(string $message): void {
        $this->writeErrorLine($message);
    }

    public function warning(string $message): void {
        $this->writeErrorLine($message);
    }

    public function writeErrorLine(string $message): void {
        $this->stderr .= $this->formatter->format($message . "\n", false);
    }

    public function info(string $message): void {
        $this->writeln($message);
    }

    public function escape(string $text): string {
        return $this->formatter->escape($text);
    }

    public function table(array $headers, array $rows): void {
        $this->write(new Table($this->formatter)->render($headers, $rows));
    }

    public function createProgressBar(int $max): IProgressBar {
        throw new LogicException('Progress bars are not supported.');
    }

    public function confirm(
        string $question,
        bool   $default = false,
        string $confirmLabel = 'Yes',
        string $cancelLabel = 'No'
    ): bool {
        throw new LogicException('Prompts are not supported.');
    }

    public function choice(string $question, array $choices, int|string|null $default = null): int|string {
        throw new LogicException('Prompts are not supported.');
    }

    public function ask(string $question, ?callable $validator = null, ?string $default = null): mixed {
        throw new LogicException('Prompts are not supported.');
    }

    public function secret(string $question, ?callable $validator = null): mixed {
        throw new LogicException('Prompts are not supported.');
    }

    public function isInteractive(): bool {
        return false;
    }

    public function getFormatter(): IOutputFormatter {
        return $this->formatter;
    }
}
