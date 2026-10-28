<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Env\EnvWriter;
use Touhidurabir\EnvLens\EnvLens;
use Touhidurabir\EnvLens\Facades\EnvLens as EnvLensFacade;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\ProductionGuard;

it('resolves the entry point as a singleton', function () {
    expect(app(EnvLens::class))->toBeInstanceOf(EnvLens::class)
        ->and(app(EnvLens::class))->toBe(app(EnvLens::class));
});

it('exposes the services', function () {
    $lens = app(EnvLens::class);
    $path = tempnam(sys_get_temp_dir(), 'envlens');
    file_put_contents($path, "A=1\n");

    expect($lens->sensitivity())->toBeInstanceOf(SensitivityDetector::class)
        ->and($lens->writer())->toBeInstanceOf(EnvWriter::class)
        ->and($lens->guard())->toBeInstanceOf(ProductionGuard::class)
        ->and($lens->file($path)->get('A'))->toBe('1');

    unlink($path);
});

it('scans usages through the facade', function () {
    expect(EnvLensFacade::usages(fixtureProject('basic'))->isUsed('DB_HOST'))->toBeTrue();
});

it('detects sensitive keys through the facade', function () {
    expect(EnvLensFacade::sensitivity()->isSensitive('APP_KEY'))->toBeTrue();
});

it('builds services from config at resolution time', function () {
    config(['envlens.sensitive.keys' => ['CUSTOM_THING']]);

    expect(EnvLensFacade::sensitivity()->isSensitive('CUSTOM_THING'))->toBeTrue();
});
