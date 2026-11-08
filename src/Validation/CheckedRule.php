<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use JsonSerializable;

/**
 * One key's rules, as shown to a person: never the rules themselves, which can be closures. For a sensitive
 * key, `$rules` holds only rule names, since a rule's parameters (`in:...`) could repeat a secret.
 */
final readonly class CheckedRule implements JsonSerializable
{
    /**
     * @param  list<string>  $rules  such as `integer`, `min:5` or `custom rule`
     * @param  string  $source  ValidationFailure::FROM_RULES or ValidationFailure::INFERRED
     * @param  string  $basedOn  the rules file, or the assumption behind an inferred rule
     */
    public function __construct(
        public string $key,
        public bool $sensitive,
        public array $rules,
        public string $source,
        public string $basedOn,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'sensitive' => $this->sensitive,
            'rules' => $this->rules,
            'source' => $this->source,
            'based_on' => $this->basedOn,
        ];
    }
}
