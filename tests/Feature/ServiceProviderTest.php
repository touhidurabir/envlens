<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Touhidurabir\EnvLens\EnvLensServiceProvider;

it('merges package config', function () {
    expect(config('envlens.files.example'))->toBe('.env.example');
});

it('publishes config under envlens-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(EnvLensServiceProvider::class, 'envlens-config');

    expect(array_keys($paths))->toHaveCount(1)
        ->and(array_keys($paths)[0])->toEndWith('config/envlens.php');
});

it('provides a fixture project helper', function () {
    mkdir(__DIR__.'/../Fixtures/projects/_tmp', 0777, true);
    file_put_contents(__DIR__.'/../Fixtures/projects/_tmp/.env', "A=1\n");

    try {
        $dir = fixtureProject('_tmp');
        expect(file_get_contents($dir.'/.env'))->toBe("A=1\n")
            ->and($dir)->toBe(realpath($dir));
    } finally {
        unlink(__DIR__.'/../Fixtures/projects/_tmp/.env');
        rmdir(__DIR__.'/../Fixtures/projects/_tmp');
    }
});
