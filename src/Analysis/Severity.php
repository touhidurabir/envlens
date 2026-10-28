<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    /** True when this severity is the same as or more severe than $other. */
    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }
}
