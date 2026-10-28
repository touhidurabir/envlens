<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Mutation\EnvSynchronizer;
use Touhidurabir\EnvLens\Scanning\CodeScanner;
use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\UsageScanner;
use Touhidurabir\EnvLens\Security\SensitivityDetector;

function cleaner(): EnvSynchronizer
{
    return new EnvSynchronizer(new SensitivityDetector(['APP_KEY'], ['*PASSWORD*', '*SECRET*'], []), ['VITE_*', 'SAIL_*']);
}

it('lists keys only in the target', function () {
    $source = EnvFile::fromString("A=1\nB=2\n");
    $target = EnvFile::fromString("Z=1\nA=1\nY=2\n");

    expect(cleaner()->extraKeys($source, $target))->toBe(['Z', 'Y']);
});

it('excludes keep patterns and dynamically protected keys from unused keys', function () {
    $scan = ['config_paths' => ['config'], 'code_paths' => ['app'], 'exclude' => ['vendor']];
    $index = (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan(fixtureProject('dynamic'), false);
    $target = EnvFile::fromString("VITE_X=1\nSAIL_XDEBUG_MODE=1\nSTRIPE_KEY=1\nMAIL_HOST=1\nORPHAN=1\n");

    expect(cleaner()->unusedKeys($target, $index))->toBe(['ORPHAN']);
});

it('plans removals', function () {
    $plan = cleaner()->planRemoval(['A', 'B']);

    expect(array_map(fn ($c) => $c->key, $plan->removes()))->toBe(['A', 'B'])->and($plan->adds())->toBe([]);
});
