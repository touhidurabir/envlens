<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

final readonly class Inference
{
    /**
     * @param  list<InferredRule>  $rules
     * @param  array{free: list<string>, nonLiteral: list<string>, conflicting: list<string>, none: list<string>}  $skipped  keys given no rule, by reason
     */
    public function __construct(
        public array $rules,
        public array $skipped,
    ) {}
}
