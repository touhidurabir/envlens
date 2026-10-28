<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Mutation\EnvSynchronizer;
use Touhidurabir\EnvLens\Security\SensitivityDetector;

function synchronizer(): EnvSynchronizer
{
    return new EnvSynchronizer(new SensitivityDetector(['APP_KEY'], ['*PASSWORD*', '*SECRET*'], []), ['VITE_*']);
}

it('lists missing keys in source order', function () {
    $source = EnvFile::fromString("B=1\nA=2\nC=3\n");
    $target = EnvFile::fromString("A=x\n");

    expect(synchronizer()->missingKeys($source, $target))->toBe(['B', 'C']);
});

it('never copies sensitive values even when asked', function () {
    $source = EnvFile::fromString("APP_KEY=base64:x\nAPP_NAME=Mine\n");
    $plan = synchronizer()->planSync($source, EnvFile::fromString(''), analysisIndex(), ['APP_KEY', 'APP_NAME'], true);

    [$key, $name] = $plan->adds();

    expect($key->key)->toBe('APP_KEY')->and($key->value)->toBe('')->and($key->valueCopied)->toBeFalse()
        ->and($name->value)->toBe('Mine')->and($name->valueCopied)->toBeTrue();
});

it('uses literal defaults only when enabled', function () {
    $source = EnvFile::fromString("DB_PORT=1\nUNKNOWN=2\n");

    $with = synchronizer()->planSync($source, EnvFile::fromString(''), analysisIndex(), [], true)->adds();
    $without = synchronizer()->planSync($source, EnvFile::fromString(''), analysisIndex(), [], false)->adds();

    expect($with[0]->value)->toBe('3306')->and($with[1]->value)->toBe('')
        ->and($without[0]->value)->toBe('');
});

it('never copies a value that is a connection string with credentials', function () {
    $source = EnvFile::fromString("DB_URL=mysql://root:SuperSecret123@localhost/db\n");
    $add = synchronizer()->planSync($source, EnvFile::fromString(''), analysisIndex(), ['DB_URL'], true)->adds()[0];

    expect($add->value)->toBe('')->and($add->valueCopied)->toBeFalse();
});

it('never fills a config default for keys in generate.skip_defaults', function () {
    $sync = new EnvSynchronizer(new SensitivityDetector([], [], []), [], ['APP_ENV', 'APP_DEBUG']);
    $adds = $sync->planSync(EnvFile::fromString("APP_ENV=local\nAPP_DEBUG=true\nAPP_NAME=x\n"), EnvFile::fromString(''), analysisIndex(), [], true)->adds();

    expect(array_map(fn ($c) => $c->value, $adds))->toBe(['', '', 'Laravel']);
});
