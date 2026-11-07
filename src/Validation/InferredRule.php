<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

/**
 * A rule guessed from the scan. `$rule` is what gets checked; `$written` is what a generated rules file
 * holds. When `$active` is false the file holds it commented out, for a person to decide.
 */
final readonly class InferredRule
{
    public function __construct(
        public string $key,
        public string $rule,
        public string $written,
        public bool $active,
        public string $assumption,
        public ?string $file,
        public ?int $line,
    ) {}
}
