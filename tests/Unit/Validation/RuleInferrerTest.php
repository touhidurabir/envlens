<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\CodeScanner;
use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\DefaultKind;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageScanner;
use Touhidurabir\EnvLens\Scanning\UsageSource;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Validation\EnvValidator;
use Touhidurabir\EnvLens\Validation\EnvValues;
use Touhidurabir\EnvLens\Validation\Inference;
use Touhidurabir\EnvLens\Validation\InferredRule;
use Touhidurabir\EnvLens\Validation\RuleInferrer;

function inferrer(): RuleInferrer
{
    return new RuleInferrer(analyzer(), new SensitivityDetector(['APP_KEY'], ['*_SECRET'], []));
}

function read(string $key, mixed $default = null, DefaultKind $kind = DefaultKind::Literal, UsageSource $source = UsageSource::Config, ?string $package = null): EnvUsage
{
    return new EnvUsage($key, 'config/a.php', 5, $source, 'a.b', $kind, $default, null, $package);
}

function ruleFor(Inference $inference, string $key): ?InferredRule
{
    foreach ($inference->rules as $rule) {
        if ($rule->key === $key) {
            return $rule;
        }
    }

    return null;
}

it('infers a type from a literal default', function (string $default, string $rule, string $assumption) {
    $inferred = ruleFor(inferrer()->infer(new UsageIndex([read('K', $default)]), null), 'K');

    expect($inferred->rule)->toBe($rule)
        ->and($inferred->active)->toBeTrue()
        ->and($inferred->assumption)->toBe($assumption)
        ->and($inferred->file)->toBe('config/a.php')
        ->and($inferred->line)->toBe(5);
})->with([
    ['3306', 'nullable|integer', 'config default 3306 is a whole number'],
    ['-1', 'nullable|integer', 'config default -1 is a whole number'],
    ['1.5', 'nullable|numeric', 'config default 1.5 is a number'],
    ['false', 'nullable|boolean', 'config default false is true/false'],
    ['true', 'nullable|boolean', 'config default true is true/false'],
    ['http://localhost', 'nullable|url', 'config default http://localhost is a URL'],
    ['https://a.test/x', 'nullable|url', 'config default https://a.test/x is a URL'],
    ['hello@example.com', 'nullable|email', 'config default hello@example.com is an email address'],
]);

it('gives no rule to a free-text default, and says why', function (string $default) {
    $inference = inferrer()->infer(new UsageIndex([read('K', $default)]), null);

    expect(ruleFor($inference, 'K'))->toBeNull()->and($inference->skipped['free'])->toBe(['K']);
})->with([
    'word' => ['stack'],
    'list of urls' => ['https://*.draw.io https://*.youtube.com'],
    'host' => ['127.0.0.1'],
    'empty' => [''],
    'non-http scheme' => ['ftp://a.test'],
]);

it('gives no rule when defaults conflict across usages', function () {
    $inference = inferrer()->infer(new UsageIndex([read('K', '1'), read('K', 'abc')]), null);

    expect(ruleFor($inference, 'K'))->toBeNull()->and($inference->skipped['conflicting'])->toBe(['K']);
});

it('gives one rule when every default agrees', function () {
    expect(ruleFor(inferrer()->infer(new UsageIndex([read('K', '1'), read('K', '2')]), null), 'K')->rule)->toBe('nullable|integer');
});

it('gives no rule to a non-literal default', function () {
    $inference = inferrer()->infer(new UsageIndex([read('K', null, DefaultKind::NonLiteral)]), null);

    expect(ruleFor($inference, 'K'))->toBeNull()->and($inference->skipped['nonLiteral'])->toBe(['K']);
});

it('gives no rule to a key with no default that is not declared', function () {
    $inference = inferrer()->infer(new UsageIndex([read('K', null, DefaultKind::None)]), EnvFile::fromString(''));

    expect(ruleFor($inference, 'K'))->toBeNull()->and($inference->skipped['none'])->toBe(['K']);
});

it('suggests required for a declared key with no default, commented out', function () {
    $inferred = ruleFor(inferrer()->infer(new UsageIndex([read('K', null, DefaultKind::None)]), EnvFile::fromString("K=\n")), 'K');

    expect($inferred->rule)->toBe('required')
        ->and($inferred->active)->toBeFalse()
        ->and($inferred->assumption)->toBe('no default, and declared required; may be optional');
});

it('always requires APP_KEY when the app reads it', function () {
    $inferred = ruleFor(inferrer()->infer(new UsageIndex([read('APP_KEY', null, DefaultKind::None)]), null), 'APP_KEY');

    expect($inferred->rule)->toBe('required')
        ->and($inferred->active)->toBeTrue()
        ->and($inferred->assumption)->toBe('Laravel cannot encrypt without it');
});

it('ignores usages from vendor config', function () {
    $framework = read('ONLY_FRAMEWORK', '5', source: UsageSource::Vendor, package: 'laravel/framework');
    $both = [read('BOTH', '5'), read('BOTH', 'text', source: UsageSource::Vendor, package: 'laravel/framework')];
    $inference = inferrer()->infer(new UsageIndex([$framework, ...$both], [], ['laravel/framework']), null);

    expect(ruleFor($inference, 'ONLY_FRAMEWORK'))->toBeNull()
        ->and(ruleFor($inference, 'BOTH')->rule)->toBe('nullable|integer');
});

it('never shows the default of a sensitive key', function () {
    $inference = inferrer()->infer(new UsageIndex([read('API_SECRET', '123456')]), null);

    expect(ruleFor($inference, 'API_SECRET')->assumption)->toBe('config default is a whole number')
        ->and(json_encode($inference))->not->toContain('123456');
});

it('infers from a stock Laravel app, and its own example passes', function (string $fixture, array $counts) {
    $path = fixtureProject($fixture);
    $scan = ['config_paths' => ['config'], 'code_paths' => ['app'], 'exclude' => ['vendor']];
    $index = (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan($path, false);
    $example = EnvFile::fromPath($path.'/.env.example');
    $inference = inferrer()->infer($index, $example);

    $tally = [];
    foreach ($inference->rules as $rule) {
        $tally[$rule->rule] = ($tally[$rule->rule] ?? 0) + 1;
    }
    ksort($tally);

    expect($tally)->toBe($counts);

    $rules = [];
    foreach ($inference->rules as $rule) {
        if ($rule->active && $rule->key !== 'APP_KEY') {
            $rules[$rule->key] = $rule->rule;
        }
    }

    $report = (new EnvValidator(new SensitivityDetector([], [], []), app(Factory::class)))->validate(EnvValues::fromFile($example), $rules);

    expect($report->failures)->toBe([]);
})->with([
    'stock-12' => ['stock-12', ['nullable|boolean' => 9, 'nullable|email' => 1, 'nullable|integer' => 15, 'nullable|url' => 2, 'required' => 9]],
    'stock-13' => ['stock-13', ['nullable|boolean' => 9, 'nullable|email' => 1, 'nullable|integer' => 15, 'nullable|url' => 2, 'required' => 9]],
]);

it('infers numeric from a float default, even a whole one', function () {
    $inferred = ruleFor(inferrer()->infer(new UsageIndex([read('RATE', '1.0')]), null), 'RATE');

    expect($inferred->rule)->toBe('nullable|numeric');
});
