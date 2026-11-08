<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Exceptions;

use InvalidArgumentException;

final class InvalidBaseline extends InvalidArgumentException
{
    public static function at(string $path): self
    {
        return new self('Could not read '.basename($path).': it is not a valid baseline. Fix it, or delete it and run php artisan env:audit --ci --update-baseline.');
    }
}
