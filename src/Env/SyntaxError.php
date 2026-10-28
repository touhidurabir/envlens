<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Env;

final readonly class SyntaxError
{
    public function __construct(
        public int $line,
        public string $message,
    ) {}
}
