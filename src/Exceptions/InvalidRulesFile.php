<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Exceptions;

use InvalidArgumentException;

/** The rules cannot be used: a usage error (exit 2). Messages never hold an env value. */
final class InvalidRulesFile extends InvalidArgumentException
{
    public static function notLoadable(string $path, string $reason): self
    {
        return new self(basename($path)." could not be loaded: {$reason}.");
    }

    public static function badShape(string $path, string $problem): self
    {
        return new self(basename($path)." {$problem}.");
    }

    public static function unknownRule(string $key, string $rule): self
    {
        return new self("Unknown rule for {$key}: {$rule}.");
    }

    /** Only the exception's class: its message could hold the value being validated. */
    public static function ruleFailed(string $key, string $exception): self
    {
        return new self("A rule for {$key} threw {$exception}.");
    }
}
