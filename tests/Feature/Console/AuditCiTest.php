<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function auditCi(array $options, int $exit = 0): string
{
    expect(Artisan::call('env:audit', ['--ci' => true] + $options))->toBe($exit);

    return Artisan::output();
}

/** A stock Laravel 13 project, plus a config file reading the given keys with no default. */
function ciProject(array $reads = []): string
{
    $path = fixtureProject('stock-13');
    $lines = array_map(fn (string $key) => "    '".strtolower($key)."' => env('{$key}'),", $reads);
    file_put_contents($path.'/config/partner.php', "<?php\n\nreturn [\n".implode("\n", $lines)."\n];\n");

    return $path;
}

it('passes a stock Laravel app with no .env and no configuration', function (string $fixture) {
    $path = fixtureProject($fixture);

    expect(file_exists($path.'/.env'))->toBeFalse();

    Artisan::call('env:audit', ['--ci' => true, '--path' => $path, '--json' => true]);
    $summary = json_decode(Artisan::output(), true)['data']['summary'];

    expect($summary['error'])->toBe(0)->and($summary['warning'])->toBe(0);
    expect(auditCi(['--path' => $path]))->toMatch('/PASS/');
})->with(['stock-12', 'stock-13']);

it('fails on a forgotten key, naming where config reads it', function () {
    $output = auditCi(['--path' => ciProject(['NEW_PARTNER_SECRET'])], 1);

    expect($output)->toMatch('/ERROR\s+undeclared-key \(1\)/')
        ->toMatch('/NEW_PARTNER_SECRET\s*\.+\s*config\/partner\.php:4/')
        ->toContain('Add NEW_PARTNER_SECRET to the example file')
        ->toContain('What the codes mean');
});

it('passes once the key is in the example file', function () {
    $path = ciProject(['NEW_PARTNER_SECRET']);
    file_put_contents($path.'/.env.example', "\nNEW_PARTNER_SECRET=\n", FILE_APPEND);

    auditCi(['--path' => $path]);
});

it('passes when the key matches envlens.optional', function () {
    config(['envlens.optional' => [...config('envlens.optional'), 'NEW_PARTNER_*']]);

    auditCi(['--path' => ciProject(['NEW_PARTNER_SECRET'])]);
});

it('fails on a real-looking secret in the example, without printing it', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env.example', "\n# STRIPE_SECRET=sk_live_marker\n", FILE_APPEND);

    expect(auditCi(['--path' => $path], 1))->toMatch('/ERROR\s+example-secret \(1\)/')
        ->not->toContain('sk_live_marker');
});

it('says the example is the contract and that no .env is needed', function () {
    expect(auditCi(['--path' => fixtureProject('stock-13')]))
        ->toContain('Checking .env.example as the contract')
        ->toContain('no .env needed');
});

it('ignores a .env on disk', function () {
    $path = fixtureProject('stock-13');
    $without = auditCi(['--path' => $path]);

    file_put_contents($path.'/.env', "ORPHAN=1\nDB_HOST=x\nDB_HOST=y\nBAD LINE\n");

    expect(auditCi(['--path' => $path]))->toBe($without);
});

it('refuses --file with --ci', function () {
    expect(auditCi(['--path' => fixtureProject('stock-13'), '--file' => '.env'], 2))->toContain('--ci checks the example file');
});

it('exits 2 when the example file is missing', function () {
    $path = fixtureProject('stock-13');
    unlink($path.'/.env.example');

    expect(auditCi(['--path' => $path], 2))->toContain('File not found: .env.example');
});

it('honours --example', function () {
    $path = fixtureProject('stock-13');
    rename($path.'/.env.example', $path.'/.env.dist');

    auditCi(['--path' => $path, '--example' => '.env.dist']);
});

it('exits 2 when no config was scanned, whatever --fail-on says', function (array $options) {
    $path = fixtureProject('stock-13');
    (new Filesystem)->deleteDirectory($path.'/config');

    expect(auditCi(['--path' => $path] + $options, 2))->toContain('scan.config_paths');
})->with([
    'default' => [[]],
    'fail-on none' => [['--fail-on' => 'none']],
    'json' => [['--json' => true]],
]);

it('fails on an unparseable config file only with --ci', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/config/bad.php', "<?php\nreturn [ this is not php\n");
    copy($path.'/.env.example', $path.'/.env');

    expect(auditCi(['--path' => $path], 1))->toMatch('/ERROR\s+scan-warning \(1\)/');

    expect(Artisan::call('env:audit', ['--path' => $path]))->toBe(0);
    expect(Artisan::output())->toMatch('/WARN\s+scan-warning \(1\)/');
});

it('lets --fail-on decide findings other than an empty scan', function () {
    auditCi(['--path' => ciProject(['NEW_PARTNER_SECRET']), '--fail-on' => 'none']);
});

it('outputs the findings as JSON with the error severity', function () {
    $data = json_decode(auditCi(['--path' => ciProject(['NEW_PARTNER_SECRET']), '--json' => true], 1), true);

    expect($data['schema'])->toBe(1)->and($data['command'])->toBe('env:audit');
    expect(collect($data['data']['findings'])->firstWhere('code', 'undeclared-key'))
        ->toMatchArray(['severity' => 'error', 'key' => 'NEW_PARTNER_SECRET', 'file' => 'config/partner.php', 'line' => 4]);
});

it('accepts current findings with --update-baseline and then blocks only new ones', function () {
    $path = ciProject(['LEGACY_ONE', 'LEGACY_TWO']);

    expect(auditCi(['--path' => $path, '--update-baseline' => true]))
        ->toContain('Recorded 2 findings in envlens-baseline.json')
        ->toContain('git add envlens-baseline.json');

    expect(auditCi(['--path' => $path]))->toContain('2 accepted by envlens-baseline.json')->toMatch('/PASS/');

    file_put_contents($path.'/config/more.php', "<?php\n\nreturn ['x' => env('BRAND_NEW')];\n");

    $output = auditCi(['--path' => $path], 1);
    expect($output)->toMatch('/BRAND_NEW\s*\.+\s*config\/more\.php:3/')->not->toMatch('/LEGACY_ONE\s*\.+/');
});

it('stays valid when a baselined line moves', function () {
    $path = ciProject(['LEGACY_ONE']);
    auditCi(['--path' => $path, '--update-baseline' => true]);

    file_put_contents($path.'/config/partner.php', "<?php\n\n\n\nreturn ['x' => env('LEGACY_ONE')];\n");

    auditCi(['--path' => $path]);
});

it('reports stale entries as info, never failing, until the baseline is updated', function () {
    $path = ciProject(['LEGACY_ONE']);
    auditCi(['--path' => $path, '--update-baseline' => true]);
    unlink($path.'/config/partner.php');

    expect(auditCi(['--path' => $path]))->toMatch('/INFO\s+stale-baseline \(1\)/');

    auditCi(['--path' => $path, '--update-baseline' => true]);
    expect(auditCi(['--path' => $path]))->not->toContain('stale-baseline');
});

it('never accepts a secret or a scan failure, and says so', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env.example', "\nSTRIPE_SECRET=sk_live_marker\n", FILE_APPEND);
    file_put_contents($path.'/config/bad.php', "<?php\nreturn [ not php\n");

    expect(auditCi(['--path' => $path, '--update-baseline' => true]))->toContain('Not recorded: 2 findings');
    expect(file_get_contents($path.'/envlens-baseline.json'))->not->toContain('sk_live_marker')->not->toContain('STRIPE_SECRET');

    auditCi(['--path' => $path], 1);
});

it('mentions --update-baseline only when something could be accepted', function () {
    expect(auditCi(['--path' => ciProject(['LEGACY_ONE'])], 1))->toContain('--update-baseline');

    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env.example', "\nSTRIPE_SECRET=sk_live_marker\n", FILE_APPEND);

    expect(auditCi(['--path' => $path], 1))->not->toContain('--update-baseline');
});

it('exits 2 with one line when the baseline is corrupt', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/envlens-baseline.json', "<<<<<<< HEAD\n{}\n");

    expect(trim(auditCi(['--path' => $path], 2)))->toContain('envlens-baseline.json')->not->toContain('Stack trace');
});

it('refuses --update-baseline without --ci and in production', function () {
    expect(Artisan::call('env:audit', ['--path' => fixtureProject('basic'), '--update-baseline' => true]))->toBe(2);
    expect(Artisan::output())->toContain('--update-baseline needs --ci');

    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', "APP_ENV=production\n");

    expect(auditCi(['--path' => $path, '--update-baseline' => true], 1))->toContain('production');
    expect(file_exists($path.'/envlens-baseline.json'))->toBeFalse();
});

it('reports the accepted count in JSON', function () {
    $path = ciProject(['LEGACY_ONE']);
    auditCi(['--path' => $path, '--update-baseline' => true]);

    expect(json_decode(auditCi(['--path' => $path, '--json' => true]), true)['data']['baselined'])->toBe(1);
});

it('does not call a key undeclared when config gives it a fallback', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/config/partner.php', "<?php\n\nreturn ['a' => env('FALLBACK_ONE') ?? 'x', 'b' => env('FALLBACK_TWO') ?: 'y'];\n");

    auditCi(['--path' => $path]);
});

it('does not fail on env() mentioned in a Blade comment or text', function () {
    $path = fixtureProject('stock-13');
    mkdir($path.'/resources/views', 0777, true);
    file_put_contents($path.'/resources/views/a.blade.php', "{{-- env('X') --}}\n<p>Set env('Y')</p>\n");

    auditCi(['--path' => $path]);
});

it('fails on a real env() call in Blade', function () {
    $path = fixtureProject('stock-13');
    mkdir($path.'/resources/views', 0777, true);
    file_put_contents($path.'/resources/views/a.blade.php', "\n{{ \\env('X') }}\n");

    expect(auditCi(['--path' => $path], 1))->toMatch('/X\s*\.+\s*resources\/views\/a\.blade\.php:2/');
});
