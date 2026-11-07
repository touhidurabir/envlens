<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

use Throwable;
use Touhidurabir\EnvLens\Exceptions\InvalidRulesFile;

/** The rules from `env.rules.php`: base rules, rules added per environment, messages and whether to infer. */
final readonly class RuleSet
{
    public const FILE = 'env.rules.php';

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, array<string, mixed>>  $environments
     * @param  array<string, string>  $messages
     */
    public function __construct(
        private array $rules = [],
        private array $environments = [],
        public array $messages = [],
        public bool $infer = true,
    ) {}

    public static function fromFile(string $path): self
    {
        try {
            $data = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable $e) {
            throw InvalidRulesFile::notLoadable($path, $e::class.' on line '.$e->getLine());
        }

        if (! is_array($data)) {
            throw InvalidRulesFile::badShape($path, 'must return an array');
        }

        $rules = $data['rules'] ?? null;
        $environments = $data['environments'] ?? [];
        $messages = $data['messages'] ?? [];
        $infer = $data['infer'] ?? true;

        if (! is_array($rules)) {
            throw InvalidRulesFile::badShape($path, "must have a 'rules' array");
        }

        if (! is_array($environments) || array_filter($environments, static fn (mixed $r): bool => ! is_array($r)) !== []) {
            throw InvalidRulesFile::badShape($path, "must have an array of rules for each of its 'environments'");
        }

        if (! is_array($messages)) {
            throw InvalidRulesFile::badShape($path, "must have a 'messages' array");
        }

        if (! is_bool($infer)) {
            throw InvalidRulesFile::badShape($path, "must have true or false for 'infer'");
        }

        return new self($rules, $environments, $messages, $infer);
    }

    /**
     * The rules for an environment: its rules are added to the base rules, not swapped for them.
     *
     * @return array<string, list<mixed>>
     */
    public function rulesFor(?string $environment): array
    {
        $rules = [];

        foreach ($this->rules as $key => $rule) {
            $rules[$key] = self::split($rule);
        }

        foreach ($this->environments[$environment ?? ''] ?? [] as $key => $rule) {
            $rules[$key] = [...($rules[$key] ?? []), ...self::split($rule)];
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    private static function split(mixed $rule): array
    {
        return match (true) {
            is_string($rule) => explode('|', $rule),
            is_array($rule) => array_values($rule),
            default => [$rule],
        };
    }
}
