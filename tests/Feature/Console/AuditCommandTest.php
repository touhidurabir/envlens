<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function audit(array $options, int $exit = 0): string
{
    expect(Artisan::call('env:audit', $options))->toBe($exit);

    return Artisan::output();
}

it('exits 1 by default when errors are found and prints findings', function () {
    $output = audit(['--path' => fixtureProject('basic')], 1);

    expect($output)->toMatch('/ERROR\s+env-outside-config \(5\)/')
        ->toMatch('/PAYMENT_URL\s*\.+\s*app\/Services\/Pay\.php:9/');
});

it('prints a header and a verdict badge', function () {
    expect(audit(['--path' => fixtureProject('basic')], 1))
        ->toContain('Auditing .env against .env.example · 4 config files, 4 code files scanned')
        ->toMatch('/FAIL\s+0 errors|FAIL\s+\d+ errors · \d+ warnings · \d+ info \(exit 1, --fail-on=error\)/');

    expect(audit(['--path' => fixtureProject('basic'), '--fail-on' => 'none']))->toMatch('/WARN\s+\d+ errors/');
});

it('lists the fixes in priority order', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^APP_KEY=.*$/m', '', (string) file_get_contents($path.'/.env'))."DB_HOST=again\n");

    $output = audit(['--path' => $path], 1);
    $fix = substr($output, (int) strpos($output, 'Fix first'), (int) strpos($output, 'What the codes mean') - (int) strpos($output, 'Fix first'));

    expect($fix)->toContain('1. Add APP_KEY to .env: required by app.key, no default.')
        ->toContain('2. Move env() out of 5 places')
        ->toContain('3. Remove the duplicate DB_HOST (.env lines 8, 15; the last one wins).')
        ->toContain('php artisan env:sync');
});

it('explains only the codes it reports', function () {
    $output = audit(['--path' => fixtureProject('basic')], 1);

    expect($output)->toContain('What the codes mean')
        ->toMatch('/env-outside-config\s+env\(\) called outside config\//')
        ->toMatch('/missing-with-default\s+not set; config falls back to its default/')
        ->not->toContain('duplicate-key')
        ->and(strpos($output, 'What the codes mean'))->toBeLessThan(strpos($output, 'ERROR  env-outside-config'));
});

it('collapses info findings unless -v', function () {
    $path = fixtureProject('basic');

    expect(audit(['--path' => $path], 1))->toMatch('/INFO\s+unused-key \(1\)\s+1 finding \(run with -v to list\)/')
        ->not->toMatch('/ORPHAN\s*\.+\s*\.env:\d+/');

    expect(audit(['--path' => $path, '-v' => true], 1))->toMatch('/ORPHAN\s*\.+\s*\.env:\d+/');
});

it('reports used keys that are empty in the file', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=', (string) file_get_contents($path.'/.env')));

    expect(audit(['--path' => $path], 1))->toMatch('/WARN\s+empty-value \(1\)\s+DB_HOST\s*\.+\s*database\.connections\.mysql\.host/')
        ->toContain('Set a value for DB_HOST in .env (it is empty), or delete it so the config default applies.');
});

it('reports keys only in the example file', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', (string) file_get_contents($path.'/.env.example')."STALE_KEY=\n");

    expect(audit(['--path' => $path], 1))->toMatch('/STALE_KEY\s*\.+\s*in \.env\.example, not in \.env/')
        ->toContain('only in the example file (STALE_KEY)');
});

it('exits 0 with --fail-on=none', function () {
    $this->artisan('env:audit', ['--path' => fixtureProject('basic'), '--fail-on' => 'none'])
        ->assertExitCode(0);
});

it('exits 2 for an invalid --fail-on', function () {
    $this->artisan('env:audit', ['--path' => fixtureProject('basic'), '--fail-on' => 'bogus'])
        ->assertExitCode(2);
});

it('says so when there is nothing to report', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=x\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\n");
    foreach (['app', 'config', 'resources', 'routes', 'storage'] as $dir) {
        (new Filesystem)->deleteDirectory($path.'/'.$dir);
    }
    mkdir($path.'/config');
    file_put_contents($path.'/config/app.php', "<?php\n\nreturn ['name' => env('APP_NAME', 'x')];\n");

    expect(audit(['--path' => $path]))->toMatch('/PASS\s+0 errors · 0 warnings · 0 info/')->toContain('No issues found.');
});

it('works without an example file', function () {
    $path = fixtureProject('basic');
    unlink($path.'/.env.example');

    expect(audit(['--path' => $path, '--fail-on' => 'none']))
        ->toContain('Auditing .env (no example file)')
        ->toMatch('/WARN\s+no-example \(1\)/')
        ->toContain('teammates get none of the 10 keys in .env')
        ->toContain('php artisan env:generate');
});

it('adds a summary and hints to the JSON output', function () {
    $doc = json_decode(audit(['--path' => fixtureProject('basic'), '--json' => true], 1), true, flags: JSON_THROW_ON_ERROR);
    $unused = collect($doc['data']['findings'])->firstWhere('code', 'unused-key');

    expect($doc['data']['summary'])->toHaveKeys(['error', 'warning', 'info'])
        ->and($doc['data']['summary']['error'])->toBe(5)
        ->and($unused['hint'])->toContain('env:cleanup --unused')
        ->and($unused['detail'])->toMatch('/^\.env:\d+$/');
});

it('outputs json with findings', function () {
    $this->artisan('env:audit', ['--path' => fixtureProject('basic'), '--json' => true])
        ->expectsOutputToContain('"unused-key"')
        ->assertExitCode(1);
});

it('exits 2 when the env file is missing', function () {
    $this->artisan('env:audit', ['--path' => fixtureProject('basic'), '--file' => '.env.nope'])
        ->assertExitCode(2);
});

it('never prints a value from a syntax error, in text or json', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', (string) file_get_contents($path.'/.env')."DB_PASSWORD=hunter2 secret value\n");

    expect(audit(['--path' => $path, '--fail-on' => 'none']))->toMatch('/ERROR\s+syntax \(1\)/')->not->toContain('hunter2');

    expect(Artisan::call('env:audit', ['--path' => $path, '--json' => true, '--fail-on' => 'none']))->toBe(0)
        ->and(Artisan::output())->toContain('"syntax"')->not->toContain('hunter2');
});

function optionalProject(): string
{
    $path = fixtureProject('vendor');
    file_put_contents($path.'/config/app.php', "<?php\nreturn ['asset_url' => env('ASSET_URL'), 'key' => env('APP_KEY')];\n");
    file_put_contents($path.'/.env', "APP_KEY=base64:x\n");
    file_put_contents($path.'/.env.example', "APP_KEY=\n");

    return $path;
}

it('reports an undeclared key without a default as missing-optional and exits 0', function () {
    expect(audit(['--path' => optionalProject(), '-v' => true]))->toMatch('/INFO\s+missing-optional \(1\)\s+ASSET_URL/')
        ->not->toContain('missing-required');
});

it('reports a key listed in envlens.required as missing-required', function () {
    config(['envlens.required' => ['ASSET_*']]);

    expect(audit(['--path' => optionalProject()], 1))->toMatch('/ERROR\s+missing-required \(1\)\s+ASSET_URL\s*\.+\s*app\.asset_url/');
});

it('reports a key declared in the example but absent from .env as missing-required', function () {
    $path = optionalProject();
    file_put_contents($path.'/.env', "OTHER=1\n");

    expect(audit(['--path' => $path], 1))->toMatch('/ERROR\s+missing-required \(1\)\s+APP_KEY\s*\.+\s*app\.key/');
});

it('prints a scanner warning once, as a scan-warning finding', function () {
    $path = fixtureProject('broken');
    file_put_contents($path.'/.env', "OK_KEY=1\n");

    expect(Artisan::call('env:audit', ['--path' => $path, '--fail-on' => 'error']))->toBe(0);

    $output = Artisan::output();

    expect(substr_count($output, 'Could not parse config/syntax.php'))->toBe(1)
        ->and($output)->toMatch('/WARN\s+scan-warning \(1\)\s+Could not parse config\/syntax\.php/');
});
