<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Analysis\KeyStatus;
use Touhidurabir\EnvLens\Env\EnvFile;

it('classifies keys', function () {
    $env = EnvFile::fromString("DB_HOST=x\nORPHAN=1\nVITE_APP_NAME=x\n");
    $report = analyzer()->keys($env, analysisIndex(), false, EnvFile::fromString("APP_KEY=\n"));

    expect(statusOf($report, 'DB_HOST'))->toBe(KeyStatus::Used)
        ->and(statusOf($report, 'ORPHAN'))->toBe(KeyStatus::Unused)
        ->and(statusOf($report, 'VITE_APP_NAME'))->toBe(KeyStatus::External)
        ->and(statusOf($report, 'APP_KEY'))->toBe(KeyStatus::Missing)
        ->and(statusOf($report, 'X'))->toBe(KeyStatus::MissingOptional)
        ->and(statusOf($report, 'DB_PORT'))->toBe(KeyStatus::MissingWithDefault)
        ->and(statusOf($report, 'PAYMENT_URL'))->toBe(KeyStatus::MissingOptional)
        ->and(KeyStatus::MissingOptional->value)->toBe('missing-optional');

    expect(statusOf(analyzer()->keys($env, analysisIndex()), 'APP_KEY'))->toBe(KeyStatus::MissingOptional)
        ->and(statusOf(analyzer(required: ['APP_KEY'])->keys($env, analysisIndex()), 'APP_KEY'))->toBe(KeyStatus::Missing);

    $host = $report->rows[0];
    expect($host->configPaths)->toBe(['database.connections.mysql.host']);
});

it('orders file keys first then sorted missing keys', function () {
    $report = analyzer()->keys(EnvFile::fromString("ORPHAN=1\nDB_HOST=x\n"), analysisIndex());
    $keys = array_map(fn ($r) => $r->key, $report->rows);

    expect(array_slice($keys, 0, 2))->toBe(['ORPHAN', 'DB_HOST']);
    $missing = array_slice($keys, 2);
    $sorted = $missing;
    sort($sorted);
    expect($missing)->toBe($sorted);
});

it('counts code usages for keys used only in code', function () {
    $report = analyzer()->keys(EnvFile::fromString("PAYMENT_URL=https://x\n"), analysisIndex());
    $row = $report->rows[0];

    expect($row->status)->toBe(KeyStatus::Used)->and($row->codeCount)->toBe(1);
});

it('never leaks sensitive values in json, even when revealing', function () {
    $env = EnvFile::fromString("APP_KEY=base64:secretvalue\nDB_HOST=hostvalue\n");

    foreach ([false, true] as $reveal) {
        $json = json_encode(analyzer()->keys($env, analysisIndex(), $reveal), JSON_THROW_ON_ERROR);
        expect($json)->not->toContain('secretvalue');
    }

    expect(json_encode(analyzer()->keys($env, analysisIndex()), JSON_THROW_ON_ERROR))->not->toContain('hostvalue')
        ->and(json_encode(analyzer()->keys($env, analysisIndex(), true), JSON_THROW_ON_ERROR))->toContain('hostvalue');
});
