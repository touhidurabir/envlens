<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use Touhidurabir\EnvLens\Security\SensitivityDetector;

/**
 * What one run will check and why: the rules file's rules for an environment, then an inferred rule for
 * each key the file doesn't cover. A written rule replaces the inferred one.
 */
final readonly class RulePlan
{
    /**
     * @param  array<string, mixed>  $rules  what the validator runs
     * @param  list<string>  $inferredKeys
     * @param  list<CheckedRule>  $checks  what to show: the file's rules, then inferred ones, each sorted by key
     */
    private function __construct(
        public string $environment,
        public array $rules,
        public array $inferredKeys,
        public array $checks,
    ) {}

    /**
     * @param  ?string  $rulesFile  the rules file's name, or null when there is none
     * @param  ?Inference  $inference  null when inference is off
     * @param  array<string, mixed>  $values
     */
    public static function build(RuleSet $set, ?string $rulesFile, string $environment, ?Inference $inference, SensitivityDetector $sensitivity, array $values): self
    {
        $rules = $set->rulesFor($environment);
        $fromEnvironment = $set->keysAddedFor($environment);
        $written = [];

        foreach ($rules as $key => $rule) {
            $basedOn = in_array($key, $fromEnvironment, true) ? "{$rulesFile} ({$environment})" : (string) $rulesFile;
            $written[] = self::check($key, $rule, ValidationFailure::FROM_RULES, $basedOn, $sensitivity, $values);
        }

        $inferredKeys = [];
        $inferred = [];

        foreach ($inference === null ? [] : $inference->rules as $rule) {
            if ($rule->active && ! isset($rules[$rule->key])) {
                $rules[$rule->key] = $rule->rule;
                $inferredKeys[] = $rule->key;
                $location = $rule->file !== null ? " ({$rule->file}:{$rule->line})" : '';
                $inferred[] = self::check($rule->key, $rule->rule, ValidationFailure::INFERRED, $rule->assumption.$location, $sensitivity, $values);
            }
        }

        return new self($environment, $rules, $inferredKeys, [...self::sorted($written), ...self::sorted($inferred)]);
    }

    public function count(string $source): int
    {
        return count(array_filter($this->checks, static fn (CheckedRule $c): bool => $c->source === $source));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function check(string $key, mixed $rule, string $source, string $basedOn, SensitivityDetector $sensitivity, array $values): CheckedRule
    {
        $value = $values[$key] ?? null;
        $sensitive = $sensitivity->isSensitive($key, is_string($value) ? $value : null);

        return new CheckedRule($key, $sensitive, self::labels($rule, $sensitive), $source, $basedOn);
    }

    /**
     * @return list<string>
     */
    private static function labels(mixed $rule, bool $sensitive): array
    {
        $parts = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? array_values($rule) : [$rule]);

        return array_map(static fn (mixed $part): string => match (true) {
            ! is_string($part) => 'custom rule',
            $sensitive => explode(':', $part, 2)[0],
            default => $part,
        }, $parts);
    }

    /**
     * @param  list<CheckedRule>  $checks
     * @return list<CheckedRule>
     */
    private static function sorted(array $checks): array
    {
        usort($checks, static fn (CheckedRule $a, CheckedRule $b): int => $a->key <=> $b->key);

        return $checks;
    }
}
