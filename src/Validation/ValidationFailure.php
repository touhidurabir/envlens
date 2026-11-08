<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use JsonSerializable;

/**
 * One key that failed. For a sensitive key, `$message` is always null, since messages can repeat the value,
 * and `$rules` holds only names, since parameters can too.
 */
final readonly class ValidationFailure implements JsonSerializable
{
    public const FROM_RULES = 'rules';

    public const INFERRED = 'inferred';

    /**
     * @param  list<string>  $rules  the rules that failed, such as `min:32` or `custom rule`
     */
    public function __construct(
        public string $key,
        public bool $sensitive,
        public string $source,
        public array $rules,
        public ?string $message,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'sensitive' => $this->sensitive,
            'source' => $this->source,
            'rules' => $this->rules,
            'message' => $this->message,
        ];
    }
}
