<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Touhidurabir\EnvLens\Scanning\CodeScanner;
use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\DefaultKind;
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

/** @return list<EnvUsage> */
function scanBladeView(string $view): array
{
    $base = sys_get_temp_dir().'/envlens-blade-'.uniqid();
    mkdir($base.'/resources/views', 0777, true);
    $GLOBALS['envLensTempDirs'][] = $base;
    file_put_contents($base.'/resources/views/page.blade.php', $view);

    return (new CodeScanner)->scan($base, ['resources/views'], [])['usages'];
}

it('ignores env() in Blade comments, PHP comments and plain text', function (string $view) {
    expect(scanBladeView($view))->toBe([]);
})->with([
    'blade comment' => ["{{-- env('X') --}}"],
    'multi-line blade comment' => ["{{--\n{{ env('X') }}\n--}}"],
    'php comment in a tag' => ["<?php /* env('X') */ ?>"],
    'php line comment' => ["<?php // env('X')\n ?>"],
    'plain text' => ["<p>Set env('X') in your file</p>"],
    'attribute text' => ['<a title="env(\'X\')">x</a>'],
    'escaped echo' => ["@{{ env('X') }}"],
    'escaped directive' => ["@@if(env('X'))"],
    'verbatim' => ["@verbatim {{ env('X') }} @endverbatim"],
    'string in an echo' => ["{{ \"env('X')\" }}"],
    'env directive' => ["@env('local') hi @endenv"],
    'method call' => ["{{ \$a->env('X') }}"],
    'static call' => ["{{ Foo::env('X') }}"],
]);

it('finds env() wherever Blade runs PHP', function (string $view) {
    $usages = scanBladeView($view);

    expect($usages)->toHaveCount(1)->and($usages[0]->key)->toBe('X');
})->with([
    'echo' => ["{{ env('X') }}"],
    'raw echo' => ['{!! env("X") !!}'],
    'space before the bracket' => ["{{ env ('X') }}"],
    'leading backslash' => ["{{ \\env('X') }}"],
    'directive' => ["@if(env('X')) a @endif"],
    'spaced directive' => ["@if (env('X')) a @endif"],
    'foreach' => ["@foreach(env('X') as \$a) @endforeach"],
    'php block' => ["@php \$a = env('X'); @endphp"],
    'php tag' => ["<?php echo env('X'); ?>"],
    'short echo tag' => ["<?= env('X') ?>"],
    'unclosed php tag' => ["<?php echo env('X');"],
    'nested in a call' => ["{{ strtoupper(env('X')) }}"],
    'facade' => ["{{ Env::get('X') }}"],
    'qualified facade' => ["{{ \\Illuminate\\Support\\Env::get('X') }}"],
]);

it('reports the right line for Blade usages', function () {
    $usages = scanBladeView("<p>a</p>\n{{-- comment\nspanning --}}\n@if(true)\n  {{\n    env('X')\n  }}\n@endif\n");

    expect($usages[0]->line)->toBe(6);
});

it('finds every call on a line, with defaults, in Blade', function () {
    $usages = scanBladeView("{{ env('A', 'x') }} {{ env('B') }}");

    expect(array_map(fn ($u) => [$u->key, $u->default, $u->defaultValue], $usages))->toBe([
        ['A', DefaultKind::Literal, 'x'],
        ['B', DefaultKind::None, null],
    ]);
});

it('reports runtime-built keys in Blade as dynamic', function () {
    expect(scanBladeView('{{ env($name) }}')[0]->isDynamic())->toBeTrue()
        ->and(scanBladeView('{{ env("X_{$name}") }}')[0]->dynamicPattern)->toBe('X_*');
});

it('sees a fallback written after the call as a default', function (string $expression, DefaultKind $kind, ?string $value) {
    $base = sys_get_temp_dir().'/envlens-fallback-'.uniqid();
    mkdir($base.'/app', 0777, true);
    $GLOBALS['envLensTempDirs'][] = $base;
    file_put_contents($base.'/app/A.php', "<?php\n\$x = {$expression};\n");

    $usage = (new CodeScanner)->scan($base, ['app'], [])['usages'][0];

    expect($usage->key)->toBe('X')->and($usage->default)->toBe($kind)->and($usage->defaultValue)->toBe($value);
})->with([
    ["env('X') ?? 'y'", DefaultKind::Literal, 'y'],
    ["env('X') ?: 'y'", DefaultKind::Literal, 'y'],
    ["env('X') ?? \$other", DefaultKind::NonLiteral, null],
    ["env('X') ?: env('Y')", DefaultKind::NonLiteral, null],
    ["env('X', 'a') ?? 'y'", DefaultKind::Literal, 'a'],
    ["env('X') ?? null", DefaultKind::Null, null],
    ["env('X') ? 'a' : 'b'", DefaultKind::None, null],
]);

it('keeps a float default looking like a float', function (string $default, string $expected) {
    $base = sys_get_temp_dir().'/envlens-float-'.uniqid();
    mkdir($base.'/app', 0777, true);
    $GLOBALS['envLensTempDirs'][] = $base;
    file_put_contents($base.'/app/A.php', "<?php\n\$x = env('X', {$default});\n");

    expect((new CodeScanner)->scan($base, ['app'], [])['usages'][0]->defaultValue)->toBe($expected);
})->with([
    ['1.0', '1.0'], ['0.0', '0.0'], ['-1.0', '-1.0'], ['1.5', '1.5'], ['-2.5', '-2.5'], ['3', '3'], ['-3', '-3'],
]);
