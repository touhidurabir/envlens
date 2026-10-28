<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use JsonSerializable;
use Touhidurabir\EnvLens\Scanning\EnvUsage;

/**
 * Everything known about one key. `$status` is null when nothing reads the key and no env
 * file defines it (then `$suggestions` holds similar known keys). `$resolves` says what the
 * app gets from the primary env file: `value`, `empty` (''), `default` (config defaults) or
 * `null`. `$values` are display values per file (masked unless revealed and not sensitive).
 */
final readonly class Explanation implements JsonSerializable
{
    /**
     * @param  list<EnvUsage>  $usages  effective usages, app config first; sensitive defaults masked
     * @param  array<string, 'set'|'empty'|'absent'>  $presence  file label => state
     * @param  list<Finding>  $findings
     * @param  list<EnvUsage>  $overridden  vendor usages replaced by the app's config at the same path
     * @param  list<EnvUsage>  $dynamic  env() calls with a runtime-built key that matches this key
     * @param  array<string, ?string>  $values  file label => display value (null when absent)
     * @param  list<string>  $suggestions
     */
    public function __construct(
        public string $key,
        public bool $sensitive,
        public array $usages,
        public array $presence,
        public array $findings,
        public ?KeyStatus $status = null,
        public string $resolves = 'null',
        public array $overridden = [],
        public array $dynamic = [],
        public array $values = [],
        public array $suggestions = [],
    ) {}

    /** True when nothing reads the key and no env file defines it. */
    public function unknown(): bool
    {
        return $this->status === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $usage = static fn (bool $overridden): \Closure => static fn (EnvUsage $u): array => $u->jsonSerialize() + ['overridden' => $overridden];

        return [
            'key' => $this->key,
            'sensitive' => $this->sensitive,
            'status' => $this->status?->value,
            'resolves' => $this->resolves,
            'usages' => array_merge(array_map($usage(false), $this->usages), array_map($usage(true), $this->overridden)),
            'dynamic' => $this->dynamic,
            'presence' => $this->presence,
            'findings' => $this->findings,
            'suggestions' => $this->suggestions,
        ];
    }
}
