<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use BadMethodCallException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
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
            $validator = $this->validatorFor($values, $key, $rule, $messages);

            $this->ensureRulesExist($validator, $key, $rule);

            if ($this->fails($validator, $key)) {
                $failures[] = $this->failure($validator, $key, $values[$key] ?? null, in_array($key, $inferredKeys, true));
            }
        }

        return new ValidationReport($failures, count($rules));
    }

    /**
     * Checks that the rules can run, without running them or looking at a value.
     *
     * @param  array<string, mixed>  $rules
     */
    public function ensureUsable(array $rules): void
    {
        foreach ($rules as $key => $rule) {
            $key = (string) $key;

            $this->ensureRulesExist($this->validatorFor([], $key, $rule, []), $key, $rule);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $messages
     */
    private function validatorFor(array $values, string $key, mixed $rule, array $messages): ValidatorContract
    {
        try {
            return $this->factory->make($values, [str_replace('.', '\\.', $key) => $rule], $messages, [$key => $key]);
        } catch (Throwable $e) {
            throw InvalidRulesFile::ruleFailed($key, $e::class);
        }
    }

    /**
     * Laravel skips most rules when a key is absent, so a misspelled rule would pass until the key is
     * set. Rule names are checked up front instead.
     */
    private function ensureRulesExist(ValidatorContract $validator, string $key, mixed $rule): void
    {
        $parts = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : [$rule]);

        foreach ($parts as $part) {
            $name = is_string($part) ? ValidationRuleParser::parse($part)[0] : null;

            if (! is_string($name) || method_exists(Validator::class, 'validate'.$name)) {
                continue;
            }

            if (! $validator instanceof Validator || ! isset($validator->extensions[Str::snake($name)])) {
                throw InvalidRulesFile::unknownRule($key, Str::snake($name));
            }
        }
    }

    private function fails(ValidatorContract $validator, string $key): bool
    {
        try {
            return $validator->fails();
        } catch (BadMethodCallException $e) {
            throw InvalidRulesFile::unknownRule($key, preg_match('/validate(\w+)/', $e->getMessage(), $m) === 1 ? Str::snake($m[1]) : 'unknown');
        } catch (Throwable $e) {
            throw InvalidRulesFile::ruleFailed($key, $e::class);
        }
    }

    private function failure(ValidatorContract $validator, string $key, mixed $value, bool $inferred): ValidationFailure
    {
        $sensitive = $this->sensitivity->isSensitive($key, is_string($value) ? $value : null);
        $failed = $validator instanceof Validator ? $validator->failed() : [];

        return new ValidationFailure(
            $key,
            $sensitive,
            $inferred ? ValidationFailure::INFERRED : ValidationFailure::FROM_RULES,
            $this->ruleNames(reset($failed) ?: [], $sensitive),
            $sensitive ? null : implode(' ', $validator->errors()->all()),
        );
    }

    /**
     * @param  array<string, array<int, mixed>>  $failed  rule name => parameters, as Validator::failed() gives them
     * @param  bool  $namesOnly  drop the parameters, since `in:...` on a sensitive key could repeat a secret
     * @return list<string>
     */
    private function ruleNames(array $failed, bool $namesOnly): array
    {
        $names = [];

        foreach ($failed as $rule => $parameters) {
            if (str_contains($rule, '\\')) {
                $names[] = 'custom rule';

                continue;
            }

            $name = Str::snake($rule);
            $names[] = $namesOnly || $parameters === [] ? $name : $name.':'.implode(',', array_map(strval(...), $parameters));
        }

        return array_values(array_unique($names));
    }
}
