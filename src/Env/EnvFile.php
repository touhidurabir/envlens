<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Env;

use Touhidurabir\EnvLens\Exceptions\EnvFileNotFound;

final class EnvFile
{
    /**
     * @param  list<EnvLine>  $lines
     * @param  list<SyntaxError>  $syntaxErrors
     */
    private function __construct(
        private readonly array $lines,
        private readonly array $syntaxErrors,
        private readonly ?string $path,
    ) {}

    public static function fromString(string $contents, ?string $path = null): self
    {
        [$lines, $errors] = (new EnvLexer)->lex($contents);

        return new self($lines, $errors, $path);
    }

    public static function fromPath(string $path): self
    {
        if (! is_file($path)) {
            throw EnvFileNotFound::at($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw EnvFileNotFound::at($path);
        }

        return self::fromString($contents, $path);
    }

    /**
     * @return list<EnvLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->lines as $line) {
            if ($line->type === EnvEntryType::Key && $line->key !== null) {
                $keys[$line->key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    public function has(string $key): bool
    {
        return $this->last($key) !== null;
    }

    /** The last definition wins. */
    public function get(string $key): ?string
    {
        return $this->last($key)?->value;
    }

    /** True when the key is defined and its value is empty (or unparseable). */
    public function isEmpty(string $key): bool
    {
        $line = $this->last($key);

        return $line !== null && ($line->value === null || $line->value === '');
    }

    /**
     * @return array<string, list<int>>
     */
    public function duplicates(): array
    {
        $seen = [];

        foreach ($this->lines as $line) {
            if ($line->type === EnvEntryType::Key && $line->key !== null) {
                $seen[$line->key][] = $line->line;
            }
        }

        return array_filter($seen, static fn (array $lines): bool => count($lines) >= 2);
    }

    /**
     * @return list<SyntaxError>
     */
    public function syntaxErrors(): array
    {
        return $this->syntaxErrors;
    }

    public function eol(): string
    {
        foreach ($this->lines as $line) {
            if (str_ends_with($line->raw, "\r\n")) {
                return "\r\n";
            }

            if (str_ends_with($line->raw, "\n")) {
                return "\n";
            }
        }

        return "\n";
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Build a new file from the given lines. Raw text is the source of truth, so
     * the result is re-lexed and line numbers and syntax errors stay consistent.
     *
     * @param  list<EnvLine>  $lines
     */
    public function withLines(array $lines): self
    {
        return self::fromString(implode('', array_map(static fn (EnvLine $l): string => $l->raw, $lines)), $this->path);
    }

    public function toString(): string
    {
        return implode('', array_map(static fn (EnvLine $l): string => $l->raw, $this->lines));
    }

    private function last(string $key): ?EnvLine
    {
        $found = null;

        foreach ($this->lines as $line) {
            if ($line->type === EnvEntryType::Key && $line->key === $key) {
                $found = $line;
            }
        }

        return $found;
    }
}
