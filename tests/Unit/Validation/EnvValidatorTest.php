<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\InvalidRulesFile;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Validation\EnvValidator;
use Touhidurabir\EnvLens\Validation\EnvValues;
use Touhidurabir\EnvLens\Validation\ValidationReport;

function envValidator(): EnvValidator
{
    return new EnvValidator(new SensitivityDetector(['APP_KEY'], ['*_SECRET'], []), app(Factory::class));
}

/** @param array<string, mixed> $rules */
function validateEnv(string $env, array $rules, array $messages = [], array $inferred = []): ValidationReport
{
    return envValidator()->validate(EnvValues::fromFile(EnvFile::fromString($env)), $rules, $messages, $inferred);
}

it('applies Laravel rules to what env() returns', function (string $env, string $rule, bool $passes) {
    expect(validateEnv($env, ['X' => $rule])->failures === [])->toBe($passes);
})->with([
    'true is boolean' => ["X=true\n", 'boolean', true],
    'null fails required' => ["X=null\n", 'required', false],
    'whole number' => ["X=3306\n", 'integer', true],
    'in range' => ["X=3306\n", 'integer|between:1,65535', true],
    'out of range' => ["X=70000\n", 'integer|between:1,65535', false],
    'between without integer measures length' => ["X=70000\n", 'between:1,65535', true],
    'empty skips integer' => ["X=\n", 'integer', true],
    'empty skips url' => ["X=\n", 'url', true],
    'empty skips boolean' => ["X=\n", 'boolean', true],
    'empty skips in' => ["X=\n", 'in:a,b', true],
    'empty skips starts_with' => ["X=\n", 'starts_with:https://', true],
    'empty passes present' => ["X=\n", 'present', true],
    'empty fails required' => ["X=\n", 'required', false],
    'empty fails accepted' => ["X=\n", 'accepted', false],
    'empty fails declined' => ["X=\n", 'declined', false],
    'empty fails filled' => ["X=\n", 'filled', false],
    'absent passes integer' => ["A=1\n", 'integer', true],
    'absent passes url' => ["A=1\n", 'url', true],
    'absent passes filled' => ["A=1\n", 'filled', true],
    'absent fails required' => ["A=1\n", 'required', false],
    'absent fails present' => ["A=1\n", 'present', false],
    'absent fails declined' => ["A=1\n", 'declined', false],
    'absent fails accepted' => ["A=1\n", 'accepted', false],
    'null fails integer' => ["X=null\n", 'integer', false],
    'null passes nullable integer' => ["X=null\n", 'nullable|integer', true],
    'false is declined' => ["X=false\n", 'declined', true],
    'true is not declined' => ["X=true\n", 'declined', false],
    'reference resolves to a url' => ["B=https://a.test\nX=\"\${B}/api\"\n", 'url', true],
]);

it('looks at other keys for rules such as required_if', function () {
    expect(validateEnv("MAIL_MAILER=smtp\n", ['MAIL_PASSWORD' => 'required_if:MAIL_MAILER,smtp'])->failures)->toHaveCount(1)
        ->and(validateEnv("MAIL_MAILER=log\n", ['MAIL_PASSWORD' => 'required_if:MAIL_MAILER,smtp'])->failures)->toBe([]);
});

it('reports every failure, with the key name and rule', function () {
    $report = validateEnv("A=x\nB=y\nC=3\n", ['A' => 'integer', 'B' => 'integer|min:5', 'C' => 'integer']);

    expect($report->checked)->toBe(3)
        ->and(array_map(fn ($f) => $f->key, $report->failures))->toBe(['A', 'B'])
        ->and($report->failures[0]->rules)->toBe(['integer'])
        ->and($report->failures[0]->message)->toBe('The A field must be an integer.')
        ->and($report->failures[0]->sensitive)->toBeFalse()
        ->and($report->failures[1]->rules)->toBe(['integer', 'min:5']);
});

it('uses custom messages for non-sensitive keys', function () {
    $report = validateEnv("A=x\n", ['A' => 'integer'], ['A.integer' => 'A must be a port.']);

    expect($report->failures[0]->message)->toBe('A must be a port.');
});

it('marks inferred rules so they can be told apart', function () {
    $report = validateEnv("A=x\nB=y\n", ['A' => 'integer', 'B' => 'integer'], [], ['B']);

    expect($report->failures[0]->source)->toBe('rules')
        ->and($report->failures[1]->source)->toBe('inferred')
        ->and($report->hasRuleFailures())->toBeTrue()
        ->and($report->hasInferredFailures())->toBeTrue()
        ->and(validateEnv("B=y\n", ['B' => 'integer'], [], ['B'])->hasRuleFailures())->toBeFalse()
        ->and(validateEnv("A=y\n", ['A' => 'integer'])->hasInferredFailures())->toBeFalse();
});

it('handles keys containing a dot', function () {
    expect(validateEnv("A.B=x\n", ['A.B' => 'integer'])->failures[0]->key)->toBe('A.B')
        ->and(validateEnv("A.B=1\n", ['A.B' => 'integer'])->failures)->toBe([]);
});

it('never exposes a sensitive value', function (mixed $rule, array $messages, array $expectedRules) {
    $report = validateEnv("STRIPE_SECRET=sk_live_marker\n", ['STRIPE_SECRET' => $rule], $messages);
    $failure = $report->failures[0];

    expect($failure->sensitive)->toBeTrue()
        ->and($failure->message)->toBeNull()
        ->and($failure->rules)->toBe($expectedRules)
        ->and(json_encode($report))->not->toContain('sk_live_marker');
})->with([
    'default message' => ['integer', [], ['integer']],
    'custom :input message' => ['integer', ['STRIPE_SECRET.integer' => 'got :input'], ['integer']],
]);

it('never exposes a sensitive value echoed by a closure', function () {
    $rule = [static function (string $attribute, mixed $value, Closure $fail): void {
        $fail("bad {$value}");
    }];
    $report = validateEnv("STRIPE_SECRET=sk_live_marker\n", ['STRIPE_SECRET' => $rule]);

    expect($report->failures[0]->rules)->toBe(['custom rule'])
        ->and($report->failures[0]->message)->toBeNull()
        ->and(json_encode($report))->not->toContain('sk_live_marker');
});

it('treats a connection string with credentials as sensitive', function () {
    $report = validateEnv("DB_URL=mysql://u:hunter2@db/app\n", ['DB_URL' => 'integer'], ['DB_URL.integer' => 'got :input']);

    expect($report->failures[0]->sensitive)->toBeTrue()
        ->and(json_encode($report))->not->toContain('hunter2');
});

it('reports an unknown rule by key, as a usage error', function () {
    expect(fn () => validateEnv("A=1\n", ['A' => 'nosuchrule']))
        ->toThrow(InvalidRulesFile::class, 'Unknown rule for A: nosuchrule');
});

it('never prints the message of an exception thrown by a rule', function () {
    $rule = [static function (string $attribute, mixed $value): void {
        throw new RuntimeException("boom {$value}");
    }];

    try {
        validateEnv("STRIPE_SECRET=sk_live_marker\n", ['STRIPE_SECRET' => $rule]);
        $this->fail('Expected InvalidRulesFile');
    } catch (InvalidRulesFile $e) {
        expect($e->getMessage())->toContain('STRIPE_SECRET')->toContain('RuntimeException')->not->toContain('sk_live_marker');
    }
});

it('reports an unknown rule even when the key is not set', function () {
    expect(fn () => validateEnv("B=1\n", ['A' => 'nosuchrule']))->toThrow(InvalidRulesFile::class, 'Unknown rule for A: nosuchrule');
});

it('accepts every kind of rule Laravel knows when the key is not set', function (mixed $rule) {
    expect(validateEnv("B=1\n", ['A' => $rule])->failures)->toBe([]);
})->with([
    'list of strings' => [['nullable', 'integer', 'min:1']],
    'sometimes' => ['sometimes|integer'],
    'bail' => ['bail|integer'],
    'exclude_if' => ['exclude_if:B,2|integer'],
    'rule with parameters' => ['between:1,5'],
    'rule object' => [fn () => Rule::in(['a', 'b'])],
]);

it('accepts a rule registered with Validator::extend', function () {
    app(Factory::class)->extend('envlens_custom', fn () => false);

    expect(validateEnv("A=1\n", ['A' => 'envlens_custom'])->failures)->toHaveCount(1);
});

it('reports a rule that is not a rule as a usage error', function () {
    expect(fn () => validateEnv("A=1\n", ['A' => new stdClass]))->toThrow(InvalidRulesFile::class, 'A rule for A threw');
});
