<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Security;

use Touhidurabir\EnvLens\Support\Glob;

final class SensitivityDetector
{
    /** scheme://user:password@host: a connection string carrying credentials. */
    private const CREDENTIAL_URL = '#^[a-z][a-z0-9+.-]*://[^/@\s]*:[^/@\s]+@#i';

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $patterns
     * @param  list<string>  $except
     */
    public function __construct(
        private readonly array $keys,
        private readonly array $patterns,
        private readonly array $except,
    ) {}

    /**
     * A key is sensitive by name (keys/patterns minus except) or, when the value is known,
     * because the value is a connection string with credentials (the name exceptions do not
     * apply to that check).
     */
    public function isSensitive(string $key, ?string $value = null): bool
    {
        if ($value !== null && preg_match(self::CREDENTIAL_URL, $value) === 1) {
            return true;
        }

        if (Glob::matchesAny($this->except, $key)) {
            return false;
        }

        return Glob::matchesAny($this->keys, $key) || Glob::matchesAny($this->patterns, $key);
    }

    /**
     * Empty: `(empty)`. Up to 4 characters: `••••` (a prefix would give away most of it).
     * Longer: the first 2 characters + `••••`.
     */
    public function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }

        if (mb_strlen($value) <= 4) {
            return '••••';
        }

        return mb_substr($value, 0, 2).'••••';
    }
}
