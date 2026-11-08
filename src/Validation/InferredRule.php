<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

/**
 * A rule guessed from the scan. When `$active` is false it is never checked, and a generated rules file
 * holds it commented out, for a person to decide: many keys Laravel lists are truly optional.
 */
final readonly class InferredRule
{
    public function __construct(
        public string $key,
        public string $rule,
        public bool $active,
        public string $assumption,
        public ?string $file,
        public ?int $line,
    ) {}
}
