<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Touhidurabir\EnvLens\Scanning\CodeScanner;
use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageScanner;
use Touhidurabir\EnvLens\Scanning\UsageSource;

function scanUsage(string $project, bool $vendor = false): UsageIndex
{
    $scan = [
        'config_paths' => ['config'],
        'code_paths' => ['app', 'routes', 'database', 'resources/views', 'bootstrap'],
        'exclude' => ['vendor', 'storage', 'node_modules', 'bootstrap/cache'],
    ];

    return (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan(fixtureProject($project), $vendor);
}

it('computes isRequired from config defaults', function () {
    $index = scanUsage('basic');

    expect($index->isRequired('APP_KEY'))->toBeTrue()
        ->and($index->isRequired('DB_HOST'))->toBeFalse()
        ->and($index->literalDefault('DB_HOST'))->toBe('127.0.0.1')
        ->and($index->configPaths('DB_HOST'))->toBe(['database.connections.mysql.host'])
        ->and($index->isUsed('APP_KEY'))->toBeTrue()
        ->and($index->keys())->toContain('APP_KEY', 'DB_HOST');
});

it('does not mark a key required when its default is non-literal', function () {
    $dir = sys_get_temp_dir().'/envlens-nl-'.uniqid();
    mkdir($dir.'/config', 0777, true);
    file_put_contents($dir.'/config/x.php', "<?php\nreturn ['a' => env('A', env('B'))];\n");
    $scan = ['config_paths' => ['config'], 'code_paths' => [], 'exclude' => []];
    $index = (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan($dir);

    expect($index->isRequired('A'))->toBeFalse()
        ->and($index->isRequired('B'))->toBeTrue();
});

it('protects keys matching a dynamic usage pattern', function () {
    $index = scanUsage('dynamic');

    expect($index->dynamic())->not->toBeEmpty()
        ->and($index->isProtectedByDynamic('STRIPE_KEY'))->toBeTrue()
        ->and($index->isProtectedByDynamic('APP_NAME'))->toBeFalse();
});

it('includes vendor config only when asked', function () {
    expect(scanUsage('vendor')->isUsed('WIDGET_TOKEN'))->toBeFalse();

    $index = scanUsage('vendor', true);
    $usage = $index->for('WIDGET_TOKEN')[0];

    expect($index->isUsed('WIDGET_TOKEN'))->toBeTrue()
        ->and($usage->package)->toBe('acme/widget')
        ->and($usage->source)->toBe(UsageSource::Vendor)
        ->and($usage->configPath)->toBe('widget.token')
        ->and($index->isRequired('WIDGET_TOKEN'))->toBeFalse();
});

it('always scans the framework config, once, and never makes it required', function () {
    $without = scanUsage('vendor');
    $with = scanUsage('vendor', true);

    expect($without->isUsed('BCRYPT_ROUNDS'))->toBeTrue()
        ->and($without->for('BCRYPT_ROUNDS'))->toHaveCount(1)
        ->and($without->for('BCRYPT_ROUNDS')[0]->source)->toBe(UsageSource::Vendor)
        ->and($without->for('BCRYPT_ROUNDS')[0]->package)->toBe('laravel/framework')
        ->and($without->for('BCRYPT_ROUNDS')[0]->configPath)->toBe('hashing.bcrypt.rounds')
        ->and($with->for('BCRYPT_ROUNDS'))->toHaveCount(1)
        ->and($with->isUsed('WIDGET_TOKEN'))->toBeTrue();
});

it('does not make a key required through vendor config without a default', function () {
    $dir = fixtureProject('vendor');
    file_put_contents($dir.'/vendor/laravel/framework/config/broadcasting.php', "<?php\nreturn ['default' => env('BROADCAST_CONNECTION')];\n");
    $scan = ['config_paths' => ['config'], 'code_paths' => [], 'exclude' => []];
    $index = (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan($dir);

    expect($index->isUsed('BROADCAST_CONNECTION'))->toBeTrue()
        ->and($index->isRequired('BROADCAST_CONNECTION'))->toBeFalse();
});

it('carries scanner warnings through', function () {
    expect(scanUsage('broken')->warnings())->not->toBeEmpty();
});

it('accepts absolute config and code paths', function () {
    $project = fixtureProject('basic');
    $elsewhere = fixtureProject('vendor');
    $scan = ['config_paths' => [$project.'/config'], 'code_paths' => [$project.'/app'], 'exclude' => []];
    $index = (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan($elsewhere);

    expect($index->isUsed('DB_HOST'))->toBeTrue()
        ->and($index->isUsed('PAYMENT_URL'))->toBeTrue()
        ->and($index->hasConfigUsages())->toBeTrue()
        ->and($index->warnings())->toBe([]);
});

it('warns when no config files were scanned', function () {
    $dir = fixtureProject('vendor');
    (new Filesystem)->deleteDirectory($dir.'/config');
    $scan = ['config_paths' => ['config'], 'code_paths' => [], 'exclude' => []];
    $index = (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan($dir);

    expect($index->hasConfigUsages())->toBeFalse()
        ->and(implode("\n", $index->warnings()))->toContain('No config files were scanned');
});
