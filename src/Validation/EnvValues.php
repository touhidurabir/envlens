<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Touhidurabir\EnvLens\Env\EnvFile;

/** Values as `env()` returns them, because that is what rules should judge: `true` not "true", `null` not "null". */
final class EnvValues
{
    /**
     * `${VAR}` references resolve, from the same file only. The file must have no syntax errors:
     * parser messages can hold the value, so callers check EnvFile::syntaxErrors() first.
     *
     * @return array<string, mixed>
     */
    public static function fromFile(EnvFile $file): array
    {
        return array_map(self::convert(...), Dotenv::parse($file->toString()));
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
