<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\DefaultKind;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageSource;
use Touhidurabir\EnvLens\Security\SensitivityDetector;

/**
 * Guesses rules from how the app reads its keys: `env('DB_PORT', 3306)` suggests an integer. Only the
 * app's own config and code count. These are guesses, so they stay `nullable`: `KEY=null` is fine for a
 * key with a default. A default that can't be typed with confidence gets no rule.
 */
final class RuleInferrer
{
    public function __construct(
        private readonly EnvAnalyzer $analyzer,
        private readonly SensitivityDetector $sensitivity,
    ) {}

    public function infer(UsageIndex $index, ?EnvFile $example): Inference
    {
        $rules = [];
        $skipped = ['free' => [], 'nonLiteral' => [], 'conflicting' => [], 'none' => []];

        foreach ($index->keys() as $key) {
            if (! $index->isReportable($key)) {
                continue;
            }

            $usages = array_values(array_filter($index->for($key), static fn (EnvUsage $u): bool => $u->source !== UsageSource::Vendor));
            $first = $usages[0] ?? null;

            if ($key === 'APP_KEY') {
                $rules[] = new InferredRule($key, 'required', 'required', true, 'Laravel cannot encrypt without it', $first?->file, $first?->line);

                continue;
            }

            if ($this->analyzer->isRequired($key, $index, $example)) {
                $rules[] = new InferredRule($key, 'present', 'required', false, 'no default, and declared required; may be optional', $first?->file, $first?->line);

                continue;
            }

            $literal = array_values(array_filter($usages, static fn (EnvUsage $u): bool => $u->default === DefaultKind::Literal));
            $nonLiteral = array_filter($usages, static fn (EnvUsage $u): bool => $u->default === DefaultKind::NonLiteral) !== [];

            if ($literal === []) {
                $skipped[$nonLiteral ? 'nonLiteral' : 'none'][] = $key;

                continue;
            }

            $types = array_unique(array_map(static fn (EnvUsage $u): string => self::typeOf((string) $u->defaultValue), $literal));

            if (count($types) > 1 || $nonLiteral) {
                $skipped['conflicting'][] = $key;
            } elseif ($types[0] === 'free') {
                $skipped['free'][] = $key;
            } else {
                $rules[] = $this->typed($key, $types[0], $literal[0]);
            }
        }

        return new Inference($rules, $skipped);
    }

    private function typed(string $key, string $type, EnvUsage $usage): InferredRule
    {
        $value = (string) $usage->defaultValue;
        $shown = $this->sensitivity->isSensitive($key, $value) ? 'config default' : "config default {$value}";
        $meaning = match ($type) {
            'integer' => 'is a whole number',
            'numeric' => 'is a number',
            'boolean' => 'is true/false',
            'url' => 'is a URL',
            default => 'is an email address',
        };
        $rule = "nullable|{$type}";

        return new InferredRule($key, $rule, $rule, true, "{$shown} {$meaning}", $usage->file, $usage->line);
    }

    private static function typeOf(string $default): string
    {
        return match (true) {
            in_array(strtolower($default), ['true', 'false'], true) => 'boolean',
            preg_match('/^-?\d+$/', $default) === 1 => 'integer',
            preg_match('/^-?\d+\.\d+$/', $default) === 1 => 'numeric',
            preg_match('#^https?://#i', $default) === 1 && filter_var($default, FILTER_VALIDATE_URL) !== false => 'url',
            filter_var($default, FILTER_VALIDATE_EMAIL) !== false => 'email',
            default => 'free',
        };
    }
}
