<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Env;

use Touhidurabir\EnvLens\Exceptions\EnvFileNotFound;

final class EnvFile
{
    /** `#KEY=value`, behind any run of comment markers: `##`, `#;`, `# -` and so on. Groups: prefix, key, value. */
    public const COMMENTED_ASSIGNMENT = '/^(\s*#[#\s;:*>\-]*(?:export\s+)?)([A-Za-z_][A-Za-z0-9_.]*)\s*=(.*)$/s';

    /** `KEY=value` inside the words of a comment, such as `# Old: KEY=value`. Groups: key, value. */
    public const PROSE_ASSIGNMENT = '/(?<![\w.])([A-Za-z_][A-Za-z0-9_.]*)=(\S+)/';

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
     * Every assignment in file order: each definition (not just the last one that wins) and each one
     * inside a comment, such as `#KEY=value` or `# Old: KEY=value`. Comments never define a key, so
     * this is only for looking at what the file holds, as secret detection does.
     *
     * @return list<EnvLine>
     */
    public function assignments(): array
    {
        $found = [];

        foreach ($this->lines as $line) {
            if ($line->type === EnvEntryType::Key && $line->key !== null) {
                $found[] = $line;
            } elseif ($line->type === EnvEntryType::Comment) {
                array_push($found, ...self::commentedAssignments($line));
            }
        }

        return $found;
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

    /**
     * @return list<EnvLine>
     */
    private static function commentedAssignments(EnvLine $line): array
    {
        $text = rtrim($line->raw, "\r\n");

        if (preg_match(self::COMMENTED_ASSIGNMENT, $text, $m) === 1) {
            [$lexed] = (new EnvLexer)->lex(substr($text, strlen($m[1]))."\n");
            $parsed = $lexed[0] ?? null;
            $value = $parsed !== null && $parsed->type === EnvEntryType::Key ? $parsed->value : null;

            return [new EnvLine(EnvEntryType::Key, $line->raw, $line->line, $m[2], $value ?? trim($m[3]))];
        }

        if (preg_match_all(self::PROSE_ASSIGNMENT, $text, $found, PREG_SET_ORDER) === 0) {
            return [];
        }

        return array_map(
            static fn (array $f): EnvLine => new EnvLine(EnvEntryType::Key, $line->raw, $line->line, $f[1], trim(rtrim($f[2], ',;.'), '"\'')),
            $found,
        );
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
