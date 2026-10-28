<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Exceptions;

use RuntimeException;

final class EnvFileNotFound extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self("File not found: {$path}");
    }
}
