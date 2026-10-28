<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

use Touhidurabir\EnvLens\Support\Glob;

final class UsageIndex
{
    /** @var array<string, list<EnvUsage>> */
    private array $byKey = [];

    /** @var list<EnvUsage> */
    private array $dynamic = [];

    /**
     * @param  list<EnvUsage>  $usages
     * @param  list<string>  $warnings
     * @param  list<string>  $backgroundPackages  vendor packages scanned only so their keys count as used;
     *                                            their usages are not reported on their own
     */
    public function __construct(
        private readonly array $usages,
        private readonly array $warnings = [],
        private readonly array $backgroundPackages = [],
    ) {
        foreach ($usages as $usage) {
            if ($usage->key === null) {
                $this->dynamic[] = $usage;
            } else {
                $this->byKey[$usage->key][] = $usage;
            }
        }
    }

    /**
     * @return list<EnvUsage>
     */
    public function all(): array
    {
        return $this->usages;
    }

    /**
     * Usages worth reporting: everything except background package usages.
     *
     * @return list<EnvUsage>
     */
    public function reportable(): array
    {
        return array_values(array_filter($this->usages, fn (EnvUsage $u): bool => ! $this->isBackground($u)));
    }

    /** True when the key has at least one usage that is not a background package usage. */
    public function isReportable(string $key): bool
    {
        foreach ($this->for($key) as $usage) {
            if (! $this->isBackground($usage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<EnvUsage>
     */
    public function for(string $key): array
    {
        return $this->byKey[$key] ?? [];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_map('strval', array_keys($this->byKey));
        sort($keys);

        return $keys;
    }

    public function isUsed(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    /**
     * @return list<EnvUsage>
     */
    public function dynamic(): array
    {
        return $this->dynamic;
    }

    public function isProtectedByDynamic(string $key): bool
    {
        foreach ($this->dynamic as $usage) {
            if ($usage->dynamicPattern !== null && Glob::matches($usage->dynamicPattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Config-required": at least one app Config usage has no default (or a null default) and
     * no usage anywhere supplies a default. Code and Vendor usages never make a key required.
     * Whether the team declared the key required is decided by the analyzer.
     */
    public function isRequired(string $key): bool
    {
        $required = false;

        foreach ($this->for($key) as $usage) {
            if ($this->hasDefaultValue($usage)) {
                return false;
            }
            // Only None/Null remain here.
            if ($usage->source === UsageSource::Config) {
                $required = true;
            }
        }

        return $required;
    }

    /** True when any usage of the key supplies a (literal or non-literal) default. */
    public function hasDefault(string $key): bool
    {
        foreach ($this->for($key) as $usage) {
            if ($this->hasDefaultValue($usage)) {
                return true;
            }
        }

        return false;
    }

    /** True when the key is read only from application code (no Config or Vendor usage). */
    public function isCodeOnly(string $key): bool
    {
        $usages = $this->for($key);

        return $usages !== [] && $usages === $this->codeUsages($key);
    }

    /** True when at least one usage (keyed or dynamic) came from the app's config files. */
    public function hasConfigUsages(): bool
    {
        foreach ($this->usages as $usage) {
            if ($usage->source === UsageSource::Config) {
                return true;
            }
        }

        return false;
    }

    public function literalDefault(string $key): ?string
    {
        foreach ($this->for($key) as $usage) {
            if ($usage->source === UsageSource::Config && $usage->default === DefaultKind::Literal) {
                return $usage->defaultValue;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function configPaths(string $key): array
    {
        $paths = [];
        foreach ($this->for($key) as $usage) {
            if ($usage->configPath !== null) {
                $paths[] = $usage->configPath;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<EnvUsage>
     */
    public function codeUsages(string $key): array
    {
        return array_values(array_filter($this->for($key), fn (EnvUsage $u) => $u->source === UsageSource::Code));
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function isBackground(EnvUsage $usage): bool
    {
        return $usage->source === UsageSource::Vendor
            && in_array($usage->package, $this->backgroundPackages, true);
    }

    private function hasDefaultValue(EnvUsage $usage): bool
    {
        return $usage->default === DefaultKind::Literal || $usage->default === DefaultKind::NonLiteral;
    }
}
