<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Mutation;

use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\Glob;

final class EnvSynchronizer
{
    /**
     * @param  list<string>  $keepPatterns
     * @param  list<string>  $skipDefaults  keys never given a config-default placeholder (generate.skip_defaults)
     */
    public function __construct(
        private readonly SensitivityDetector $sensitivity,
        private readonly array $keepPatterns,
        private readonly array $skipDefaults = [],
    ) {}

    /**
     * Keys defined in $source but not in $target, in source order.
     *
     * @return list<string>
     */
    public function missingKeys(EnvFile $source, EnvFile $target): array
    {
        return array_values(array_filter(
            $source->keys(),
            static fn (string $key): bool => ! $target->has($key),
        ));
    }

    /**
     * Sensitive keys are never given a value, even when listed in $copyValuesFor.
     *
     * @param  list<string>  $copyValuesFor
     */
    public function planSync(EnvFile $source, EnvFile $target, UsageIndex $index, array $copyValuesFor, bool $useDefaults): Plan
    {
        $changes = [];

        foreach ($this->missingKeys($source, $target) as $key) {
            if ($this->sensitivity->isSensitive($key, $source->get($key))) {
                $changes[] = new Change(ChangeType::Add, $key, '', false, ChangeReason::Secret);

                continue;
            }

            if (in_array($key, $copyValuesFor, true)) {
                $changes[] = new Change(ChangeType::Add, $key, $source->get($key) ?? '', true, ChangeReason::Copied);

                continue;
            }

            $default = $index->literalDefault($key);
            [$value, $reason] = match (true) {
                ! $useDefaults => ['', ChangeReason::DefaultsOff],
                Glob::matchesAny($this->skipDefaults, $key) => ['', ChangeReason::SkipDefault],
                $default !== null && $default !== '' => [$default, ChangeReason::ConfigDefault],
                default => ['', ChangeReason::NoDefault],
            };
            $changes[] = new Change(ChangeType::Add, $key, $value, false, $reason);
        }

        return new Plan($changes);
    }

    /**
     * Keys defined in $target but not in $source, in target order.
     *
     * @return list<string>
     */
    public function extraKeys(EnvFile $source, EnvFile $target): array
    {
        return array_values(array_filter(
            $target->keys(),
            static fn (string $key): bool => ! $source->has($key),
        ));
    }

    /**
     * Target keys nothing reads, minus dynamic matches and cleanup.keep patterns.
     *
     * @return list<string>
     */
    public function unusedKeys(EnvFile $target, UsageIndex $index): array
    {
        return array_values(array_filter(
            $target->keys(),
            fn (string $key): bool => $this->keepReason($key, $index) === null,
        ));
    }

    /**
     * Why cleanup must keep a key, or null when it may go: `read` (config or code reads it),
     * `dynamic` (a runtime-built env() key matches it) or `keep` (envlens.cleanup.keep).
     */
    public function keepReason(string $key, UsageIndex $index): ?string
    {
        return match (true) {
            $index->isUsed($key) => 'read',
            $index->isProtectedByDynamic($key) => 'dynamic',
            Glob::matchesAny($this->keepPatterns, $key) => 'keep',
            default => null,
        };
    }

    /**
     * @param  list<string>  $keys
     */
    public function planRemoval(array $keys): Plan
    {
        return new Plan(array_map(
            static fn (string $key): Change => new Change(ChangeType::Remove, $key),
            $keys,
        ));
    }
}
