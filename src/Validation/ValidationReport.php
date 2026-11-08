<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use JsonSerializable;

final readonly class ValidationReport implements JsonSerializable
{
    /**
     * @param  list<ValidationFailure>  $failures
     * @param  int  $checked  keys that had rules to check
     */
    public function __construct(
        public array $failures,
        public int $checked,
    ) {}

    public function hasRuleFailures(): bool
    {
        return $this->count(ValidationFailure::FROM_RULES) > 0;
    }

    public function hasInferredFailures(): bool
    {
        return $this->count(ValidationFailure::INFERRED) > 0;
    }

    public function count(string $source): int
    {
        return count(array_filter($this->failures, static fn (ValidationFailure $f): bool => $f->source === $source));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'checked' => $this->checked,
            'failures' => $this->failures,
            'summary' => [
                'rules' => $this->count(ValidationFailure::FROM_RULES),
                'inferred' => $this->count(ValidationFailure::INFERRED),
            ],
        ];
    }
}
