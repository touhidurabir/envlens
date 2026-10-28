<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Touhidurabir\EnvLens\Scanning\CodeScanner;
use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageSource;

function scanCode(?string $base = null, array $paths = ['app', 'routes', 'resources', 'database', 'storage'], array $exclude = ['storage']): array
{
    return (new CodeScanner)->scan($base ?? fixtureProject('basic'), $paths, $exclude);
}

/** @param list<EnvUsage> $usages */
function codeUsage(array $usages, string $key): ?EnvUsage
{
    foreach ($usages as $u) {
        if ($u->key === $key) {
            return $u;
        }
    }

    return null;
}

it('finds env calls in php files with relative paths and lines', function () {
    $u = codeUsage(scanCode()['usages'], 'PAYMENT_URL');

    expect($u)->not->toBeNull()
        ->and($u->file)->toBe('app/Services/Pay.php')
        ->and($u->line)->toBe(9)
        ->and($u->source)->toBe(UsageSource::Code)
        ->and($u->configPath)->toBeNull();
    expect(codeUsage(scanCode()['usages'], 'LEGACY_FLAG')->file)->toBe('app/Legacy/allowed.php');
});

it('recognises Env::getOrFail in php and blade', function () {
    $usages = scanCode()['usages'];

    expect(codeUsage($usages, 'ROUTE_SECRET')->file)->toBe('routes/web.php')
        ->and(codeUsage($usages, 'HOME_SUBTITLE')->line)->toBe(2);
});

it('finds env calls in blade views', function () {
    $u = codeUsage(scanCode()['usages'], 'HOME_TITLE');

    expect($u->file)->toBe('resources/views/home.blade.php')->and($u->line)->toBe(1);
});

it('honours exclusions and skips missing directories', function () {
    $result = scanCode();

    expect(codeUsage($result['usages'], 'STORAGE_SHOULD_NOT_APPEAR'))->toBeNull()
        ->and($result['warnings'])->toBe([]);
    expect(codeUsage(scanCode(paths: ['storage'], exclude: [])['usages'], 'STORAGE_SHOULD_NOT_APPEAR'))->not->toBeNull();
});

it('resolves literal concatenation to a key', function () {
    $base = fixtureProject('basic');
    file_put_contents($base.'/app/Cat.php', "<?php\nreturn env('A'.'B');\n");

    $u = codeUsage(scanCode($base)['usages'], 'AB');
    expect($u)->not->toBeNull()->and($u->dynamicPattern)->toBeNull();
});

it('emits repo-relative paths when the base path is not realpathed', function () {
    $real = fixtureProject('basic');
    $link = sys_get_temp_dir().'/envlens-link-'.uniqid();
    symlink($real, $link);

    try {
        $u = codeUsage(scanCode($link)['usages'], 'PAYMENT_URL');
        expect($u->file)->toBe('app/Services/Pay.php');

        $config = (new ConfigScanner)->scan($link, $link.'/config');
        expect($config['usages'])->not->toBeEmpty();
        foreach ($config['usages'] as $cu) {
            expect($cu->file)->toStartWith('config/');
        }
    } finally {
        (new Filesystem)->delete($link);
    }
});

it('ignores blade directives and method calls named env', function () {
    $base = fixtureProject('vendor');
    mkdir($base.'/resources/views', 0777, true);
    file_put_contents($base.'/resources/views/page.blade.php', <<<'BLADE'
        @env('local')
            <p>local</p>
        @endenv
        @production
            <p>prod</p>
        @endproduction
        {{ $app->env('x') }}
        {{ app()->env('y') }}
        {{ Foo::env('z') }}
        {{ env('REAL_KEY') }}
        BLADE);

    $usages = scanCode($base, ['resources/views'], [])['usages'];

    expect(array_map(fn (EnvUsage $u) => $u->key, $usages))->toBe(['REAL_KEY']);
});

it('still matches Env::get and Env::getOrFail in blade', function () {
    $base = fixtureProject('vendor');
    mkdir($base.'/resources/views', 0777, true);
    file_put_contents($base.'/resources/views/page.blade.php', "{{ Env::get('A_KEY') }} {{ \\Illuminate\\Support\\Env::getOrFail('B_KEY') }} {{env('C_KEY')}}\n");

    $usages = scanCode($base, ['resources/views'], [])['usages'];

    expect(array_map(fn (EnvUsage $u) => $u->key, $usages))->toBe(['A_KEY', 'B_KEY', 'C_KEY']);
});
