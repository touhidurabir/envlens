<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Support\Env;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\UnparseableEnvFile;

/** Values as `env()` returns them, because that is what rules should judge: `true` not "true", `null` not "null". */
final class EnvValues
{
    /**
     * `${VAR}` references resolve, from the same file only. phpdotenv reads the whole file where the
     * lexer reads line by line, so it can reject a file with no syntax errors. Its message can hold
     * the value, so it is replaced.
     *
     * @return array<string, mixed>
     *
     * @throws UnparseableEnvFile
     */
    public static function fromFile(EnvFile $file): array
    {
        try {
            return array_map(self::convert(...), Dotenv::parse($file->toString()));
        } catch (InvalidFileException) {
            throw UnparseableEnvFile::make();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromProcess(): array
    {
        $names = array_keys([...getenv(), ...$_ENV, ...$_SERVER]);
        $repository = Env::getRepository();
        $values = [];

        foreach ($names as $name) {
            $name = (string) $name;

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) === 1 && $repository->get($name) !== null) {
                $values[$name] = Env::get($name);
            }
        }

        return $values;
    }

    private static function convert(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $converted = match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };

        if (is_string($converted) && preg_match('/\A([\'"])(.*)\1\z/s', $converted, $m) === 1) {
            return $m[2];
        }

        return $converted;
    }
}
