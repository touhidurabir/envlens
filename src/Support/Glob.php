<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Support;

final class Glob
{
    public static function matches(string $pattern, string $subject): bool
    {
        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/isu';

        return preg_match($regex, $subject) === 1;
    }

    /**
     * @param  list<string>  $patterns
     */
    public static function matchesAny(array $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }
}
