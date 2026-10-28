<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\DefaultKind;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageSource;

function scanFixture(string $name): array
{
    $base = fixtureProject($name);

    return (new ConfigScanner)->scan($base, $base.'/config');
}

/** @param list<EnvUsage> $usages */
function usageFor(array $usages, string $key): EnvUsage
{
    foreach ($usages as $usage) {
        if ($usage->key === $key) {
            return $usage;
        }
    }

    throw new RuntimeException("No usage for {$key}");
}

it('maps env calls to dotted config paths with defaults', function () {
    $result = scanFixture('basic');
    $u = $result['usages'];

    $host = usageFor($u, 'DB_HOST');
    expect($host->configPath)->toBe('database.connections.mysql.host')
        ->and($host->default)->toBe(DefaultKind::Literal)
        ->and($host->defaultValue)->toBe('127.0.0.1')
        ->and($host->file)->toBe('config/database.php')
        ->and($host->line)->toBe(11)
        ->and($host->source)->toBe(UsageSource::Config);

    $debug = usageFor($u, 'APP_DEBUG');
    expect($debug->configPath)->toBe('app.debug')
        ->and($debug->default)->toBe(DefaultKind::Literal)
        ->and($debug->defaultValue)->toBe('false');

    expect(usageFor($u, 'APP_KEY')->default)->toBe(DefaultKind::None)
        ->and(usageFor($u, 'APP_KEY')->defaultValue)->toBeNull();

    $x = usageFor($u, 'X');
    expect($x->default)->toBe(DefaultKind::Null)->and($x->defaultValue)->toBeNull();

    expect(usageFor($u, 'DB_PORT')->defaultValue)->toBe('3306')
        ->and(usageFor($u, 'DB_PASSWORD')->defaultValue)->toBe('')
        ->and($result['warnings'])->toBe([]);
});

it('records nested env defaults at the same path', function () {
    $u = scanFixture('basic')['usages'];

    $a = usageFor($u, 'A');
    $b = usageFor($u, 'B');
    expect($a->configPath)->toBe('services.nested_default')
        ->and($a->default)->toBe(DefaultKind::NonLiteral)
        ->and($b->configPath)->toBe('services.nested_default')
        ->and($b->default)->toBe(DefaultKind::Literal)
        ->and($b->defaultValue)->toBe('b');
});

it('uses nested directories as dotted prefix and detects Env::get', function () {
    $u = scanFixture('basic')['usages'];

    expect(usageFor($u, 'THING_ENABLED')->configPath)->toBe('nested.thing.enabled')
        ->and(usageFor($u, 'THING_ENABLED')->defaultValue)->toBe('true')
        ->and(usageFor($u, 'THING_ENABLED')->file)->toBe('config/nested/thing.php')
        ->and(usageFor($u, 'ENVGET_KEY')->configPath)->toBe('services.envget');
});

it('detects dynamic keys and builds glob patterns', function () {
    $u = scanFixture('dynamic')['usages'];
    $byPath = [];
    foreach ($u as $usage) {
        $byPath[$usage->configPath] = $usage;
    }

    expect($byPath['dyn.interpolated']->isDynamic())->toBeTrue()
        ->and($byPath['dyn.interpolated']->dynamicPattern)->toBe('*_KEY')
        ->and($byPath['dyn.concat']->dynamicPattern)->toBe('MAIL_*')
        ->and($byPath['dyn.variable']->key)->toBeNull()
        ->and($byPath['dyn.variable']->dynamicPattern)->toBeNull();
});

it('warns on unparseable files and still scans the rest', function () {
    $result = scanFixture('broken');

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toStartWith('Could not parse config/syntax.php: ')
        ->and(usageFor($result['usages'], 'OK_KEY')->configPath)->toBe('ok.fine')
        ->and(usageFor($result['usages'], 'MERGED_KEY')->configPath)->toBeNull();
});

it('serializes usages', function () {
    $usage = usageFor(scanFixture('basic')['usages'], 'APP_NAME');

    expect($usage->jsonSerialize())->toMatchArray([
        'key' => 'APP_NAME', 'config_path' => 'app.name', 'default' => 'literal',
        'default_value' => 'Laravel', 'source' => 'config', 'package' => null,
    ]);
});

it('recognises Env::getOrFail including the fully qualified form', function () {
    $base = fixtureProject('basic');
    file_put_contents($base.'/config/extra.php', "<?php\nreturn ['a' => Env::getOrFail('OR_FAIL'), 'b' => \\Illuminate\\Support\\Env::getOrFail('OR_FAIL_FQ'), 'c' => env('X'.'Y')];\n");

    $usages = (new ConfigScanner)->scan($base, $base.'/config')['usages'];

    expect(usageFor($usages, 'OR_FAIL')->configPath)->toBe('extra.a')
        ->and(usageFor($usages, 'OR_FAIL_FQ')->configPath)->toBe('extra.b')
        ->and(usageFor($usages, 'XY')->dynamicPattern)->toBeNull();
});
