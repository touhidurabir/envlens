<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use BadMethodCallException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Throwable;
use Touhidurabir\EnvLens\Exceptions\InvalidRulesFile;
use Touhidurabir\EnvLens\Security\SensitivityDetector;

/**
 * Runs Laravel's validator over env values, unchanged. A sensitive key's messages are dropped, since a
 * custom message or a closure can repeat the value; only the failed rule's name is kept.
 */
final class EnvValidator
{
    public function __construct(
        private readonly SensitivityDetector $sensitivity,
        private readonly Factory $factory,
    ) {}

    /**
     * Keys run one at a time, so an unknown rule or a rule that throws can be blamed on its key. All
     * values are passed each time, so rules such as required_if can see the other keys.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  list<string>  $inferredKeys  keys whose rule was inferred rather than written
     */
    public function validate(array $values, array $rules, array $messages = [], array $inferredKeys = []): ValidationReport
    {
        $failures = [];

        foreach ($rules as $key => $rule) {
            $key = (string) $key;
            $validator = $this->factory->make($values, [str_replace('.', '\\.', $key) => $rule], $messages, [$key => $key]);

            if ($this->fails($validator, $key)) {
                $failures[] = $this->failure($validator, $key, $values[$key] ?? null, in_array($key, $inferredKeys, true));
            }
        }

        return new ValidationReport($failures, count($rules));
    }

    private function fails(\Illuminate\Contracts\Validation\Validator $validator, string $key): bool
    {
        try {
            return $validator->fails();
        } catch (BadMethodCallException $e) {
            throw InvalidRulesFile::unknownRule($key, preg_match('/validate(\w+)/', $e->getMessage(), $m) === 1 ? Str::snake($m[1]) : 'unknown');
        } catch (Throwable $e) {
            throw InvalidRulesFile::ruleFailed($key, $e::class);
        }
    }

    private function failure(\Illuminate\Contracts\Validation\Validator $validator, string $key, mixed $value, bool $inferred): ValidationFailure
    {
        $sensitive = $this->sensitivity->isSensitive($key, is_string($value) ? $value : null);
        $failed = $validator instanceof Validator ? $validator->failed() : [];

        return new ValidationFailure(
            $key,
            $sensitive,
            $inferred ? ValidationFailure::INFERRED : ValidationFailure::FROM_RULES,
            $this->ruleNames(reset($failed) ?: []),
            $sensitive ? null : implode(' ', $validator->errors()->all()),
        );
    }

    /**
     * @param  array<string, array<int, mixed>>  $failed  rule name => parameters, as Validator::failed() gives them
     * @return list<string>
     */
    private function ruleNames(array $failed): array
    {
        $names = [];

        foreach ($failed as $rule => $parameters) {
            if (str_contains($rule, '\\')) {
                $names[] = 'custom rule';

                continue;
            }

            $name = Str::snake($rule);
            $names[] = $parameters === [] ? $name : $name.':'.implode(',', array_map(strval(...), $parameters));
        }

        return array_values(array_unique($names));
    }
}
