<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Security\SensitivityDetector;

it('explains presence across files and carries config paths', function () {
    $e = analyzer()->explain('DB_HOST', [
        '.env' => EnvFile::fromString("DB_HOST=x\n"),
        '.env.example' => EnvFile::fromString("DB_HOST=\n"),
    ], analysisIndex());

    expect($e->presence)->toBe(['.env' => 'set', '.env.example' => 'empty'])
        ->and($e->usages[0]->configPath)->toBe('database.connections.mysql.host')
        ->and($e->sensitive)->toBeFalse();
});

it('reports absent keys and related findings', function () {
    $example = EnvFile::fromString("APP_KEY=\n");
    $e = analyzer()->explain('APP_KEY', ['.env' => EnvFile::fromString(''), '.env.example' => $example], analysisIndex(), $example);

    expect($e->presence)->toBe(['.env' => 'absent', '.env.example' => 'empty'])
        ->and($e->sensitive)->toBeTrue()
        ->and($e->findings[0]->message)->toBe('APP_KEY is required by config (app.key) but missing from .env');

    $optional = analyzer()->explain('APP_KEY', ['.env' => EnvFile::fromString('')], analysisIndex());

    expect($optional->findings[0]->code->value)->toBe('missing-optional');
});

it('masks sensitive literal defaults in json', function () {
    $analyzer = new EnvAnalyzer(
        new SensitivityDetector(['DB_HOST'], [], []),
        [],
        [],
    );
    $e = $analyzer->explain('DB_HOST', ['.env' => EnvFile::fromString("DB_HOST=x\n")], analysisIndex());

    expect(json_encode($e, JSON_THROW_ON_ERROR))->not->toContain('127.0.0.1');
});
