<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Exceptions;

use RuntimeException;

final class ProductionGuardException extends RuntimeException
{
    public static function refusing(string $what): self
    {
        return new self("Refusing to {$what} in production.");
    }
}
