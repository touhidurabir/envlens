<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Support;

final class CiEnvironment
{
    public static function isGitHubActions(): bool
    {
        return self::enabled('GITHUB_ACTIONS');
    }

    /** True on any CI system that follows the common `CI=true` convention, including GitHub Actions. */
    public static function isCi(): bool
    {
        return self::enabled('CI') || self::isGitHubActions();
    }

    private static function enabled(string $name): bool
    {
        $value = getenv($name);

        return is_string($value) && in_array(strtolower($value), ['true', '1'], true);
    }
}
