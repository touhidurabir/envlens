<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console\Concerns;

use Symfony\Component\Console\Output\OutputInterface;

/** GitHub Actions workflow commands, which show a problem on the file and line in a pull request. */
trait WritesAnnotations
{
    /** Written raw, because console tag formatting would change the message. */
    protected function annotate(string $level, string $title, string $message, ?string $file = null, ?int $line = null): void
    {
        $this->output->writeln($this->annotation($level, $title, $message, $file, $line), OutputInterface::OUTPUT_RAW);
    }

    protected function annotation(string $level, string $title, string $message, ?string $file = null, ?int $line = null): string
    {
        $properties = [];

        if ($file !== null) {
            $properties[] = 'file='.$this->escapeProperty(str_replace('\\', '/', $file));
        }

        if ($line !== null) {
            $properties[] = "line={$line}";
        }

        $properties[] = 'title='.$this->escapeProperty($title);

        return "::{$level} ".implode(',', $properties).'::'.$this->escapeData($message);
    }

    private function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function escapeProperty(string $value): string
    {
        return str_replace([':', ','], ['%3A', '%2C'], $this->escapeData($value));
    }
}
