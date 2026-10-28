<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Env;

final readonly class EnvLine
{
    /**
     * @param  string  $raw  The exact source text, including its own line ending(s).
     * @param  int  $line  1-based start line.
     */
    public function __construct(
        public EnvEntryType $type,
        public string $raw,
        public int $line,
        public ?string $key = null,
        public ?string $value = null,
        public bool $exported = false,
    ) {}
}
