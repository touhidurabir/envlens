<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Mutation;

final readonly class Change
{
    /**
     * @param  bool  $valueCopied  True when the value came from the source file rather than a default or blank.
     * @param  ?ChangeReason  $reason  why an added key gets its value
     */
    public function __construct(
        public ChangeType $type,
        public string $key,
        public ?string $value = null,
        public bool $valueCopied = false,
        public ?ChangeReason $reason = null,
    ) {}
}
