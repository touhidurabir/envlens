<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function explain(array $options, int $exit = 0): string
{
    expect(Artisan::call('env:explain', $options))->toBe($exit);

    return Artisan::output();
}

it('explains a key', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', str_replace('DB_HOST=127.0.0.1', 'DB_HOST=', (string) file_get_contents($path.'/.env.example')));

    expect(explain(['key' => 'DB_HOST', '--path' => $path]))
        ->toMatch('/DB_HOST\s+used/')
        ->toContain('Read by config or code and set in .env.')
        ->toMatch('/The app gets\s*\.+\s*12•••• \(from \.env\)/')
        ->toMatch('/database\.connections\.mysql\.host\s*\.+\s*config\/database\.php:\d+ · default 127\.0\.0\.1/')
        ->toMatch('/\.env\s*\.+\s*✔ 12••••/')
        ->toMatch('/\.env\.example\s*\.+\s*✘ empty/');
});

it('says the app gets null for a missing required key and shows the fix', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^APP_KEY=.*\R/m', '', (string) file_get_contents($path.'/.env')));

    expect(explain(['key' => 'APP_KEY', '--path' => $path]))
        ->toMatch('/APP_KEY\s+sensitive\s+missing/')
        ->toMatch('/The app gets\s*\.+\s*null \(not set in \.env\)/')
        ->toMatch('/\.env\s*\.+\s*– not in file/')
        ->toMatch('/ERROR\s+missing-required/')
        ->toContain('Fix: Add APP_KEY to .env.');
});

it('says an empty value is passed as an empty string', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=', (string) file_get_contents($path.'/.env')));

    expect(explain(['key' => 'DB_HOST', '--path' => $path]))
        ->toContain('Read by config or code, but empty in .env.')
        ->toMatch("/The app gets\\s*\\.+\\s*'' \\(empty string; config defaults are not used\\)/")
        ->toMatch('/WARN\s+empty-value/');
});

it('says a missing key with a default gets the config default', function () {
    expect(explain(['key' => 'APP_URL', '--path' => fixtureProject('basic')]))
        ->toMatch('/APP_URL\s+missing-with-default/')
        ->toMatch('/The app gets\s*\.+\s*its config default/');
});

it('shows values with --reveal but keeps secrets masked', function () {
    expect(explain(['key' => 'APP_NAME', '--path' => fixtureProject('basic'), '--reveal' => true]))
        ->toMatch('/The app gets\s*\.+\s*My App \(from \.env\)/');

    expect(explain(['key' => 'APP_KEY', '--path' => fixtureProject('basic'), '--reveal' => true]))
        ->not->toContain('secretvalue');
});

it('refuses --reveal in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('env:explain', ['key' => 'APP_NAME', '--path' => fixtureProject('basic'), '--reveal' => true])
        ->expectsOutputToContain('Refusing to reveal values in production.')
        ->assertExitCode(1);
});

it('reports an unknown key with exit 1 and suggests similar keys', function () {
    expect(explain(['key' => 'NOPE', '--path' => fixtureProject('basic')], 1))
        ->toContain('NOPE is not read by any config or code and not defined in any env file.')
        ->not->toContain('Did you mean');

    expect(explain(['key' => 'DB_HOTS', '--path' => fixtureProject('basic')], 1))
        ->toContain('Did you mean: DB_HOST');
});

it('explains a key only in the example file', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', (string) file_get_contents($path.'/.env.example')."STALE_FLAG=\n");

    expect(explain(['key' => 'STALE_FLAG', '--path' => $path]))
        ->toMatch('/STALE_FLAG\s+unused/')
        ->toContain('Only in .env.example; nothing reads it.')
        ->toMatch('/WARN\s+example-drift/');
});

it('requires a key when non-interactive', function () {
    $this->artisan('env:explain', ['--path' => fixtureProject('basic'), '--no-interaction' => true])
        ->expectsOutputToContain('Provide a key.')
        ->assertExitCode(2);
});

it('picks a key interactively', function () {
    $this->artisan('env:explain', ['--path' => fixtureProject('basic')])
        ->expectsSearch('Which key do you want to explain?', 'DB_HOST', 'DB_H', ['DB_HOST'])
        ->expectsOutputToContain('database.connections.mysql.host')
        ->assertExitCode(0);
});

it('outputs json', function () {
    $doc = json_decode(explain(['key' => 'DB_HOST', '--path' => fixtureProject('basic'), '--json' => true]), true, flags: JSON_THROW_ON_ERROR);

    expect($doc['command'])->toBe('env:explain')
        ->and($doc['data']['status'])->toBe('used')
        ->and($doc['data']['resolves'])->toBe('value')
        ->and($doc['data']['usages'][0]['overridden'])->toBeFalse();
});

it('outputs suggestions and exit 1 as json for an unknown key', function () {
    $doc = json_decode(explain(['key' => 'DB_HOTS', '--path' => fixtureProject('basic'), '--json' => true], 1), true, flags: JSON_THROW_ON_ERROR);

    expect($doc['data']['status'])->toBeNull()->and($doc['data']['suggestions'])->toContain('DB_HOST');
});
