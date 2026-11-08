<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Analysis\Finding;
use Touhidurabir\EnvLens\Analysis\FindingCode;
use Touhidurabir\EnvLens\Analysis\Severity;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\DefaultKind;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageSource;

function configRead(string $key, DefaultKind $default = DefaultKind::None, UsageSource $source = UsageSource::Config, ?string $package = null): EnvUsage
{
    return new EnvUsage($key, 'config/app.php', 7, $source, 'app.x', $default, $default === DefaultKind::Literal ? 'x' : null, null, $package);
}

it('reports a key config reads with no default that the example does not list', function () {
    $report = analyzer()->contract(EnvFile::fromString("A=1\n"), new UsageIndex([configRead('PARTNER_SECRET'), configRead('A')]));
    $found = findingsOf($report, FindingCode::UndeclaredKey);

    expect($found)->toHaveCount(1)
        ->and($found[0]->key)->toBe('PARTNER_SECRET')
        ->and($found[0]->severity())->toBe(Severity::Error)
        ->and($found[0]->file)->toBe('config/app.php')
        ->and($found[0]->line)->toBe(7)
        ->and($found[0]->detail)->toBe('config/app.php:7')
        ->and($found[0]->hint)->toContain('envlens.optional');
});

it('does not report a key that has a default, is in the example, is optional or is read by the framework', function () {
    $index = new UsageIndex([
        configRead('WITH_DEFAULT', DefaultKind::Literal),
        configRead('LISTED'),
        configRead('OPTIONAL_KEY'),
        configRead('FRAMEWORK_KEY'),
        configRead('FRAMEWORK_KEY', source: UsageSource::Vendor, package: 'laravel/framework'),
    ]);

    expect(findingsOf(analyzer(optional: ['OPTIONAL_*'])->contract(EnvFile::fromString("LISTED=\n"), $index), FindingCode::UndeclaredKey))->toBe([]);
});

it('still reports a key read by another vendor package', function () {
    $index = new UsageIndex([configRead('K'), configRead('K', source: UsageSource::Vendor, package: 'acme/pkg')]);

    expect(findingsOf(analyzer()->contract(EnvFile::fromString(''), $index), FindingCode::UndeclaredKey))->toHaveCount(1);
});

it('reports a key matching both required and optional, because the example is the contract', function () {
    $index = new UsageIndex([configRead('K')]);

    expect(findingsOf(analyzer(required: ['K'], optional: ['K'])->contract(EnvFile::fromString(''), $index), FindingCode::UndeclaredKey))->toHaveCount(1);
});

it('reports example secrets, syntax, duplicates and unused keys from the example at their severities', function () {
    $example = EnvFile::fromString("STRIPE_SECRET=sk_live_x\nORPHAN=1\nDUP=1\nDUP=2\n BAD LINE\n");
    $report = analyzer()->contract($example, new UsageIndex([configRead('STRIPE_SECRET'), configRead('DUP')]));

    expect(findingsOf($report, FindingCode::ExampleSecret)[0]->severity())->toBe(Severity::Error)
        ->and(findingsOf($report, FindingCode::Syntax)[0]->severity())->toBe(Severity::Error)
        ->and(findingsOf($report, FindingCode::DuplicateKey)[0]->severity())->toBe(Severity::Warning)
        ->and(array_map(fn (Finding $f) => $f->key, findingsOf($report, FindingCode::UnusedKey)))->toBe(['ORPHAN'])
        ->and(json_encode($report))->not->toContain('sk_live');
});

it('respects cleanup.keep for unused keys', function () {
    expect(findingsOf(analyzer()->contract(EnvFile::fromString("VITE_APP_NAME=x\n"), new UsageIndex([])), FindingCode::UnusedKey))->toBe([]);
});

it('makes scan warnings errors, in the JSON too', function () {
    $report = analyzer()->contract(EnvFile::fromString(''), new UsageIndex([], ['Could not parse config/bad.php']));
    $found = findingsOf($report, FindingCode::ScanWarning);

    expect($found[0]->severity())->toBe(Severity::Error)
        ->and($report->has(Severity::Error))->toBeTrue()
        ->and(json_decode((string) json_encode($found[0]), true)['severity'])->toBe('error');
});

it('never runs the checks that need real values', function () {
    $report = analyzer()->contract(EnvFile::fromString("EMPTY_ONE=\n"), new UsageIndex([configRead('EMPTY_ONE'), configRead('ABSENT', DefaultKind::Literal)]));

    expect(findingsOf($report, FindingCode::EmptyValue))->toBe([])
        ->and(findingsOf($report, FindingCode::MissingWithDefault))->toBe([])
        ->and(findingsOf($report, FindingCode::MissingOptional))->toBe([])
        ->and(findingsOf($report, FindingCode::ExampleDrift))->toBe([]);
});

it('reports env() outside config and dynamic keys', function () {
    $code = new EnvUsage('X', 'app/A.php', 3, UsageSource::Code, null, DefaultKind::None, null, null);
    $dynamic = new EnvUsage(null, 'config/d.php', 4, UsageSource::Config, null, DefaultKind::None, null, '*_TOKEN');
    $report = analyzer()->contract(EnvFile::fromString(''), new UsageIndex([$code, $dynamic]));

    expect(findingsOf($report, FindingCode::EnvOutsideConfig)[0]->severity())->toBe(Severity::Error)
        ->and(findingsOf($report, FindingCode::DynamicKey)[0]->severity())->toBe(Severity::Warning);
});
