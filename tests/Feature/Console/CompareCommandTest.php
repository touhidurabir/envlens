<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\EnvLens;

function syncExample(string $path): void
{
    // Also define the keys the fixture requires, so nothing is missing from both files.
    file_put_contents($path.'/.env', (string) file_get_contents($path.'/.env')."\nENVGET_KEY=a\nX=b\n");
    copy($path.'/.env', $path.'/.env.example');
}

it('compares .env against .env.example by default', function () {
    $this->artisan('env:compare', ['--path' => fixtureProject('basic')])
        ->expectsOutputToContain('Comparing .env → .env.example')
        ->expectsOutputToContain('PAYMENT_URL')
        ->expectsOutputToContain('ORPHAN')
        ->expectsOutputToContain('empty in .env.example')
        ->assertExitCode(0);
});

it('exits 1 under --strict when a key is missing from the target', function () {
    $this->artisan('env:compare', ['--path' => fixtureProject('basic'), '--strict' => true])
        ->assertExitCode(1);
});

it('exits 0 under --strict when in sync', function () {
    $path = fixtureProject('basic');
    syncExample($path);

    $this->artisan('env:compare', ['--path' => $path, '--strict' => true])
        ->expectsOutputToContain('.env and .env.example are in sync.')
        ->assertExitCode(0);
});

it('does not fail --strict on differing values alone', function () {
    $path = fixtureProject('basic');
    syncExample($path);
    file_put_contents($path.'/.env.example', str_replace('DB_PORT=3306', 'DB_PORT=5432', (string) file_get_contents($path.'/.env.example')));

    $this->artisan('env:compare', ['--path' => $path, '--strict' => true])
        ->expectsOutputToContain('different')
        ->assertExitCode(0);
});

it('exits 1 with --fail-on-empty when the target has empty values', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', (string) file_get_contents($path.'/.env')."\n");
    $example = (string) file_get_contents($path.'/.env.example');
    file_put_contents($path.'/.env.example', $example."PAYMENT_URL=\nORPHAN=1\n");

    $this->artisan('env:compare', ['--path' => $path, '--fail-on-empty' => true])
        ->assertExitCode(1);
});

it('exits 2 when source and target are the same file', function () {
    $this->artisan('env:compare', ['source' => '.env', 'target' => './.env', '--path' => fixtureProject('basic')])
        ->expectsOutputToContain('Source and target are the same file.')
        ->assertExitCode(2);
});

it('prints the lists and a summary as JSON', function () {
    expect(Artisan::call('env:compare', ['--path' => fixtureProject('basic'), '--json' => true]))->toBe(0);

    $doc = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($doc['command'])->toBe('env:compare')
        ->and(array_keys($doc['data']))->toBe(['only_in_source', 'only_in_target', 'different', 'empty_in_target', 'missing_from_both', 'same', 'summary'])
        ->and($doc['data']['only_in_source'])->toContain('PAYMENT_URL', 'ORPHAN');
});

it('returns exit 1 with valid JSON under --strict --json', function () {
    expect(Artisan::call('env:compare', ['--path' => fixtureProject('basic'), '--strict' => true, '--json' => true]))->toBe(1);

    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['schema'])->toBe(1);
});

it('reveals only non-sensitive values', function () {
    $this->artisan('env:compare', ['--path' => fixtureProject('basic'), '--reveal' => true])
        ->expectsOutputToContain('https://pay.test')
        ->doesntExpectOutputToContain('secretvalue')
        ->doesntExpectOutputToContain('supersecret')
        ->assertExitCode(0);
});

it('hides values without --reveal', function () {
    $this->artisan('env:compare', ['--path' => fixtureProject('basic')])
        ->doesntExpectOutputToContain('https://pay.test')
        ->assertExitCode(0);
});

it('refuses --reveal in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('env:compare', ['--path' => fixtureProject('basic'), '--reveal' => true])
        ->expectsOutputToContain('Refusing to reveal values in production.')
        ->assertExitCode(1);
});

it('exits 2 when the target file is missing', function () {
    $this->artisan('env:compare', ['target' => '.env.nope', '--path' => fixtureProject('basic')])
        ->assertExitCode(2);
});

it('lists required keys missing from both only when declared', function () {
    $path = fixtureProject('basic');

    expect(Artisan::call('env:compare', ['--path' => $path, '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['missing_from_both'])->toBe([]);

    config(['envlens.required' => ['X']]);
    $this->app->forgetInstance(EnvAnalyzer::class);
    $this->app->forgetInstance(EnvLens::class);

    expect(Artisan::call('env:compare', ['--path' => $path, '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['missing_from_both'])->toBe(['X']);
});

it('does not reveal a connection string with credentials', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nDB_URL=mysql://root:SuperSecret123@localhost/db\n");
    file_put_contents($path.'/.env.example', "APP_NAME=Laravel\n");

    $this->artisan('env:compare', ['--path' => $path, '--reveal' => true])
        ->expectsOutputToContain('DB_URL')
        ->doesntExpectOutputToContain('SuperSecret123')
        ->assertExitCode(0);
});

it('defaults its files to envlens.files.source and envlens.files.example', function () {
    $path = fixtureProject('basic');
    unlink($path.'/.env.example');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nONLY_REAL=1\n");
    file_put_contents($path.'/.env.dist', "APP_NAME=\nONLY_DIST=1\n");
    config(['envlens.files.example' => '.env.dist']);

    expect(Artisan::call('env:compare', ['--path' => $path]))->toBe(0);
    expect(Artisan::output())->toMatch('/ONLY_REAL\s*\|.*only in \.env\s*\|/')
        ->toMatch('/ONLY_DIST\s*\|.*only in \.env\.dist\s*\|/');

    file_put_contents($path.'/.env.local', "APP_NAME=\nONLY_DIST=1\n");
    config(['envlens.files.source' => '.env.local']);

    $this->artisan('env:compare', ['--path' => $path])
        ->expectsOutputToContain('.env.local and .env.dist are in sync.')
        ->assertExitCode(0);
});

it('uses the configured file names in the other commands', function () {
    $path = fixtureProject('basic');
    rename($path.'/.env.example', $path.'/.env.dist');
    config(['envlens.files.example' => '.env.dist']);

    expect(Artisan::call('env:sync', ['--dry-run' => true, '--path' => $path]))->toBe(0)
        ->and(Artisan::output())->toMatch('/Syncing \.env → \.env\.dist.*\n(.*\n)*\|\s*ORPHAN\s/');
    $this->artisan('env:cleanup', ['--dry-run' => true, '--path' => $path])
        ->expectsOutputToContain('Nothing to clean up in .env.dist.')
        ->assertExitCode(0);
    expect(Artisan::call('env:audit', ['--path' => $path, '--fail-on' => 'none']))->toBe(0)
        ->and(Artisan::output())->toMatch('/ORPHAN\s*\.+\s*in \.env, not in \.env\.dist/');
    expect(Artisan::call('env:explain', ['key' => 'DB_HOST', '--path' => $path]))->toBe(0)
        ->and(Artisan::output())->toMatch('/\.env\.dist\s*\.+\s*✔/');
    $this->artisan('env:generate', ['--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('.env.dist exists. Use --force to overwrite.')
        ->assertExitCode(1);
});

it('prints one table row per key with presence icons and the difference', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nAPP_KEY=base64:secretvalue\nDB_PORT=3306\nONLY_HERE=1\n");
    file_put_contents($path.'/.env.staging', "APP_NAME=Mine\nAPP_KEY=\nDB_PORT=5432\nONLY_THERE=\n");

    expect(Artisan::call('env:compare', ['source' => '.env', 'target' => '.env.staging', '--path' => $path]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toMatch('/\|\s*Key\s*\|\s*\.env\s*\|\s*\.env\.staging\s*\|\s*Sensitive\s*\|\s*Difference\s*\|/')
        ->toMatch('/ONLY_HERE\s*\|\s*✔\s*\|\s*–\s*\|\s*–\s*\|\s*only in \.env\s*\|/')
        ->toMatch('/ONLY_THERE\s*\|\s*–\s*\|\s*✘\s*\|\s*–\s*\|\s*only in \.env\.staging\s*\|/')
        ->toMatch('/DB_PORT\s*\|\s*✔\s*\|\s*✔\s*\|\s*–\s*\|\s*different\s*\|/')
        ->toMatch('/APP_KEY\s*\|\s*✔\s*\|\s*✘\s*\|\s*✔\s*\|\s*empty in \.env\.staging\s*\|/')
        ->not->toMatch('/\|\s*APP_NAME\s/')
        ->not->toContain('secretvalue');
});

it('prints a legend of the differences shown and a summary', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nDB_PORT=3306\nONLY_HERE=1\n");
    file_put_contents($path.'/.env.staging', "APP_NAME=Mine\nDB_PORT=5432\n");

    expect(Artisan::call('env:compare', ['source' => '.env', 'target' => '.env.staging', '--path' => $path]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toMatch('/only in \.env\s*\.+\s*defined in \.env, not in \.env\.staging/')
        ->toMatch('/different\s*\.+\s*set in both, with different values/')
        ->not->toMatch('/empty in \.env\.staging\s*\.+/')
        ->toMatch('/Keys in \.env \/ \.env\.staging\s*\.+\s*3 \/ 2/')
        ->toMatch('/Same\s*\.+\s*1/')
        ->toMatch('/Different \/ only in \.env \/ only in \.env\.staging\s*\.+\s*1 \/ 1 \/ 0/')
        ->and(strpos($output, 'set in both'))->toBeLessThan(strpos($output, '| Key '))
        ->and(strpos($output, 'Summary'))->toBeGreaterThan(strpos($output, '| DB_PORT '));
});

it('marks empty and differing values as expected against the example file', function () {
    $this->artisan('env:compare', ['--path' => fixtureProject('basic')])
        ->expectsOutputToContain('empty there (expected for secrets)')
        ->assertExitCode(0);
});

it('lists keys with the same value with --all', function () {
    expect(Artisan::call('env:compare', ['--path' => fixtureProject('basic'), '--all' => true]))->toBe(0);

    expect(Artisan::output())->toMatch('/DB_HOST\s*\|\s*✔\s*\|\s*✔\s*\|\s*–\s*\|\s*same\s*\|/');
});

it('shows the table under --all even when the files are in sync', function () {
    $path = fixtureProject('basic');
    syncExample($path);

    expect(Artisan::call('env:compare', ['--path' => $path, '--all' => true]))->toBe(0);

    expect(Artisan::output())->toMatch('/DB_HOST\s*\|.*same/')->toContain('.env and .env.example are in sync.');
});

it('filters to one kind of difference with --only', function () {
    expect(Artisan::call('env:compare', ['--path' => fixtureProject('basic'), '--only' => 'source']))->toBe(0);
    $output = Artisan::output();

    expect($output)->toMatch('/ORPHAN\s*\|.*only in \.env\s*\|/')
        ->not->toMatch('/\|\s*empty in \.env\.example/');
});

it('says so when --only matches nothing', function () {
    $this->artisan('env:compare', ['--path' => fixtureProject('basic'), '--only' => 'missing'])
        ->expectsOutputToContain('No keys to list.')
        ->assertExitCode(0);
});

it('rejects an unknown --only value', function () {
    $this->artisan('env:compare', ['--path' => fixtureProject('basic'), '--only' => 'bogus'])
        ->expectsOutputToContain('Invalid --only value "bogus". Use one of: source, target, different, empty, missing.')
        ->assertExitCode(2);
});

it('shows values in the file columns with --reveal', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "DB_PORT=3306\nAPP_KEY=base64:secretvalue\n");
    file_put_contents($path.'/.env.staging', "DB_PORT=5432\nAPP_KEY=\n");

    expect(Artisan::call('env:compare', ['source' => '.env', 'target' => '.env.staging', '--path' => $path, '--reveal' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toMatch('/DB_PORT\s*\|\s*3306\s*\|\s*5432\s*\|/')
        ->toMatch('/APP_KEY\s*\|\s*ba••••\s*\|\s*\(empty\)\s*\|/')
        ->not->toContain('secretvalue');
});
