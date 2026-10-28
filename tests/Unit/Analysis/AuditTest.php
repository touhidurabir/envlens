<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Analysis\AuditReport;
use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\Analysis\Finding;
use Touhidurabir\EnvLens\Analysis\FindingCode;
use Touhidurabir\EnvLens\Analysis\Severity;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Security\SensitivityDetector;

it('flags env() outside config unless allowed', function () {
    $report = analyzer(['app/Legacy/*'])->audit(EnvFile::fromString("DB_HOST=x\n"), null, analysisIndex());
    $files = array_map(fn (Finding $f) => $f->file, findingsOf($report, FindingCode::EnvOutsideConfig));

    expect($files)->toContain('app/Services/Pay.php')->not->toContain('app/Legacy/allowed.php');
    expect(findingsOf($report, FindingCode::EnvOutsideConfig)[0]->severity())->toBe(Severity::Error);
});

it('produces findings with table severities and readable messages', function () {
    $env = EnvFile::fromString("DB_HOST=x\nDB_HOST=y\nORPHAN=1\n BAD LINE\n");
    $example = EnvFile::fromString("DB_HOST=\nAPP_KEY=\n");
    $r = analyzer()->audit($env, $example, analysisIndex());

    $missing = findingsOf($r, FindingCode::MissingRequired);
    expect(array_map(fn ($f) => $f->key, $missing))->toContain('APP_KEY')
        ->and(collect($missing)->firstWhere('key', 'APP_KEY')->message)
        ->toBe('APP_KEY is required by config (app.key) but missing from .env')
        ->and($missing[0]->severity())->toBe(Severity::Error)
        ->and(findingsOf($r, FindingCode::DuplicateKey)[0]->key)->toBe('DB_HOST')
        ->and(findingsOf($r, FindingCode::DuplicateKey)[0]->severity())->toBe(Severity::Warning)
        ->and(findingsOf($r, FindingCode::UnusedKey)[0]->key)->toBe('ORPHAN')
        ->and(findingsOf($r, FindingCode::UnusedKey)[0]->severity())->toBe(Severity::Info)
        ->and(findingsOf($r, FindingCode::ExampleDrift)[0]->key)->toBe('ORPHAN')
        ->and(findingsOf($r, FindingCode::MissingWithDefault))->not->toBeEmpty()
        ->and(findingsOf($r, FindingCode::Syntax))->not->toBeEmpty();
});

it('skips example drift without an example', function () {
    $r = analyzer()->audit(EnvFile::fromString("ORPHAN=1\n"), null, analysisIndex());

    expect(findingsOf($r, FindingCode::ExampleDrift))->toBe([]);
});

it('groups usages by file', function () {
    $r = analyzer()->audit(EnvFile::fromString(''), null, analysisIndex());

    expect(array_keys($r->usagesByFile))->toContain('app/Services/Pay.php');
});

it('computes worst and has', function () {
    $dup = new AuditReport([], [new Finding(FindingCode::DuplicateKey, 'x')]);
    $none = new AuditReport([], []);

    expect($dup->has(Severity::Warning))->toBeTrue()
        ->and($dup->has(Severity::Error))->toBeFalse()
        ->and($dup->worst())->toBe(Severity::Warning)
        ->and($none->worst())->toBeNull()
        ->and($none->has(Severity::Info))->toBeFalse();
});

it('orders severities', function () {
    expect(Severity::Error->atLeast(Severity::Warning))->toBeTrue()
        ->and(Severity::Info->atLeast(Severity::Warning))->toBeFalse()
        ->and(Severity::Info->atLeast(Severity::Info))->toBeTrue();
});

it('masks sensitive literal defaults in audit json but shows non-sensitive ones', function () {
    $analyzer = new EnvAnalyzer(new SensitivityDetector(['DB_PORT'], [], []), [], []);
    $json = json_encode($analyzer->audit(EnvFile::fromString(''), null, analysisIndex()), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('3306')->and($json)->toContain('127.0.0.1');
});

it('reports a config key without a default as required only when the example declares it', function () {
    $env = EnvFile::fromString("DB_HOST=x\n");
    $declared = analyzer()->audit($env, EnvFile::fromString("APP_KEY=\n"), analysisIndex());
    $undeclared = analyzer()->audit($env, EnvFile::fromString("DB_HOST=\n"), analysisIndex());

    $required = collect(findingsOf($declared, FindingCode::MissingRequired))->firstWhere('key', 'APP_KEY');
    $optional = collect(findingsOf($undeclared, FindingCode::MissingOptional))->firstWhere('key', 'APP_KEY');

    expect($required)->not->toBeNull()
        ->and($required->severity())->toBe(Severity::Error)
        ->and(collect(findingsOf($undeclared, FindingCode::MissingRequired))->firstWhere('key', 'APP_KEY'))->toBeNull()
        ->and($optional)->not->toBeNull()
        ->and($optional->severity())->toBe(Severity::Info)
        ->and(FindingCode::MissingOptional->value)->toBe('missing-optional');
});

it('reports a key listed in envlens.required as required', function () {
    $r = analyzer(required: ['APP_*'])->audit(EnvFile::fromString("DB_HOST=x\n"), null, analysisIndex());

    expect(collect(findingsOf($r, FindingCode::MissingRequired))->pluck('key')->all())->toContain('APP_KEY')
        ->not->toContain('X');
});

it('reports a key used only in code as optional, not as having a default', function () {
    $r = analyzer()->audit(EnvFile::fromString("DB_HOST=x\n"), null, analysisIndex());
    $payment = collect(findingsOf($r, FindingCode::MissingOptional))->firstWhere('key', 'PAYMENT_URL');

    expect($payment)->not->toBeNull()
        ->and($payment->message)->toBe('PAYMENT_URL is used in code but not set in .env')
        ->and(collect(findingsOf($r, FindingCode::MissingWithDefault))->pluck('key')->all())
        ->not->toContain('PAYMENT_URL')->not->toContain('HOME_TITLE')->toContain('DB_PORT');
});

it('reports scanner warnings as scan-warning findings', function () {
    $index = new UsageIndex([], ['Could not parse config/syntax.php: boom']);
    $r = analyzer()->audit(EnvFile::fromString(''), null, $index);
    $warnings = findingsOf($r, FindingCode::ScanWarning);

    expect(FindingCode::ScanWarning->value)->toBe('scan-warning')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toBe('Could not parse config/syntax.php: boom')
        ->and($warnings[0]->severity())->toBe(Severity::Warning)
        ->and(findingsOf($r, FindingCode::Syntax))->toBe([]);
});
