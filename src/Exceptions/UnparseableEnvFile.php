<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Exceptions;

use RuntimeException;

/** phpdotenv rejected the file as a whole. Its own message can hold a value, so it is never repeated. */
final class UnparseableEnvFile extends RuntimeException
{
    public static function make(): self
    {
        return new self('The file could not be parsed as a whole: check for an unbalanced quote.');
    }
}
