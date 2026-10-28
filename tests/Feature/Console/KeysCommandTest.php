<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('lists keys with config usage and masks sensitive values', function () {
    expect(Artisan::call('env:keys', ['--path' => fixtureProject('basic')]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('DB_HOST')
        ->toContain('database.connections.mysql.host')
        ->not->toContain('secretvalue');
});

it('prints each config path of a key on its own line, with a separator between keys', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/config/multi.php', "<?php\n\nreturn ['one' => env('APP_NAME'), 'two' => env('APP_NAME')];\n");

    expect(Artisan::call('env:keys', ['--path' => $path]))->toBe(0);
    $lines = explode("\n", Artisan::output());

    $first = array_search(
        array_values(array_filter($lines, fn (string $l) => str_contains($l, '| APP_NAME ')))[0],
        $lines,
        true,
    );

    // APP_NAME's row continues on the following lines with one config path each.
    expect($lines[$first])->toContain('app.name')->not->toContain(',')
        ->and($lines[$first + 1])->toMatch('/^\|\s+\|\s+\|\s+\|\s+multi\.(one|two)\s+\|/')
        ->and($lines[$first + 2])->toMatch('/^\|\s+\|\s+\|\s+\|\s+multi\.(one|two)\s+\|/')
        // ...and the next key starts after a separator line.
        ->and($lines[$first + 3])->toStartWith('+-');
});

it('shows ticks for set and sensitive keys and crosses for keys without a value', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nAPP_KEY=base64:secretvalue\nDB_HOST=\n");

    expect(Artisan::call('env:keys', ['--path' => $path]))->toBe(0);
    $lines = explode("\n", Artisan::output());
    $row = fn (string $key): string => array_values(array_filter($lines, fn (string $l) => str_contains($l, "| {$key} ")))[0];

    expect(implode("\n", $lines))->toMatch('/\|\s*Key\s*\|\s*Value\s*\|\s*Sensitive\s*\|\s*Used by Config\s*\|\s*Used In\s*\|\s*Status\s*\|/')
        ->and($row('APP_NAME'))->toMatch('/APP_NAME\s*\|\s*✔\s*\|\s*–\s*\|/')
        ->and($row('APP_KEY'))->toMatch('/APP_KEY\s*\|\s*✔\s*\|\s*✔\s*\|/')
        ->and($row('DB_HOST'))->toMatch('/DB_HOST\s*\|\s*✘\s*\|/')
        ->and(implode("\n", $lines))->not->toContain('Mine')->not->toContain('base64');
});

it('prints a legend for the statuses the table shows', function () {
    expect(Artisan::call('env:keys', ['--path' => fixtureProject('basic')]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toMatch('/Status\b.*\n.*used\s*\.+\s*read by config or code/')
        ->toMatch('/unused\s*\.+\s*in the file, nothing reads it/')
        ->not->toContain('missing-with-default')
        ->and(strpos($output, 'read by config or code'))->toBeLessThan(strpos($output, '| Key '));
});

it('prints a summary after the table', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=', (string) file_get_contents($path.'/.env')));

    expect(Artisan::call('env:keys', ['--path' => $path]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toMatch('/Total keys\s*\.+\s*10/')
        ->toMatch('/With a value\s*\.+\s*9/')
        ->toMatch('/Need a value\s*\.+\s*1/')
        ->toMatch('/Sensitive \/ not sensitive\s*\.+\s*2 \/ 8/')
        ->and(strpos($output, 'Total keys'))->toBeGreaterThan(strpos($output, '| ORPHAN '));
});

it('counts required keys in the summary', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^APP_KEY=.*\R/m', '', (string) file_get_contents($path.'/.env')));

    expect(Artisan::call('env:keys', ['--path' => $path, '--only' => 'missing']))->toBe(0);

    expect(Artisan::output())->toMatch('/Need a value\s*\.+\s*\d+ \(1 required\)/');
});

it('adds a summary and has_value to the JSON output', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nDB_HOST=\nORPHAN=\n");

    expect(Artisan::call('env:keys', ['--path' => $path, '--json' => true]))->toBe(0);
    $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data'];
    $rows = array_column($data['rows'], null, 'key');

    expect($rows['APP_NAME']['has_value'])->toBeTrue()
        ->and($rows['DB_HOST']['has_value'])->toBeFalse()
        ->and($data['summary'])->toBe([
            'total' => 3,
            'with_value' => 1,
            'need_value' => 1, // ORPHAN is empty too, but nothing reads it
            'required_missing' => 0,
            'sensitive' => 0,
            'not_sensitive' => 3,
        ]);
});

it('reveals non-sensitive values but still masks sensitive ones', function () {
    $this->artisan('env:keys', ['--path' => fixtureProject('basic'), '--reveal' => true])
        ->expectsOutputToContain('My App')
        ->doesntExpectOutputToContain('secretvalue')
        ->doesntExpectOutputToContain('supersecret')
        ->assertExitCode(0);
});

it('refuses to reveal in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('env:keys', ['--path' => fixtureProject('basic'), '--reveal' => true])
        ->expectsOutputToContain('Refusing to reveal values in production.')
        ->assertExitCode(1);
});

it('prints only a JSON document with --json', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^APP_KEY=.*\R/m', '', (string) file_get_contents($path.'/.env')));

    expect(Artisan::call('env:keys', ['--path' => $path, '--json' => true, '--missing' => true]))->toBe(0);

    $doc = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $rows = array_column($doc['data']['rows'], null, 'key');

    expect($doc['schema'])->toBe(1)
        ->and($doc['command'])->toBe('env:keys')
        ->and($rows['APP_KEY']['status'])->toBe('missing')
        ->and($rows['APP_KEY']['in_file'])->toBeFalse()
        ->and($rows['APP_KEY']['source'])->toBe(['app'])
        ->and($rows['PAYMENT_URL']['source'])->toBe(['code'])
        ->and($rows['DB_HOST']['in_file'])->toBeTrue();
});

it('filters to unused keys with --only=unused', function () {
    $this->artisan('env:keys', ['--path' => fixtureProject('basic'), '--only' => 'unused'])
        ->expectsOutputToContain('ORPHAN')
        ->doesntExpectOutputToContain('DB_HOST')
        ->assertExitCode(0);
});

it('filters to sensitive keys with --only=sensitive', function () {
    $this->artisan('env:keys', ['--path' => fixtureProject('basic'), '--only' => 'sensitive'])
        ->expectsOutputToContain('DB_PASSWORD')
        ->doesntExpectOutputToContain('DB_HOST')
        ->assertExitCode(0);
});

it('fails with exit 2 when the file is missing', function () {
    $this->artisan('env:keys', ['--path' => fixtureProject('basic'), '--file' => '.env.missing'])
        ->expectsOutputToContain('File not found: .env.missing')
        ->assertExitCode(2);
});

it('adds keys the file does not define with --missing', function () {
    expect(Artisan::call('env:keys', ['--path' => fixtureProject('basic'), '--missing' => true]))->toBe(0);

    expect(Artisan::output())->toMatch('/HOME_TITLE\s*\|\s*✘.*missing-optional/')->toContain('DB_HOST');
});

it('lists only missing app keys with --only=missing', function () {
    expect(Artisan::call('env:keys', ['--path' => fixtureProject('basic'), '--only' => 'missing']))->toBe(0);

    expect(Artisan::output())->toMatch('/HOME_TITLE\s.*missing-optional/')->not->toContain('DB_HOST');
});

it('lists only keys defined in the file by default', function () {
    expect(Artisan::call('env:keys', ['--path' => fixtureProject('basic')]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('DB_HOST')->toContain('ORPHAN')
        ->not->toContain('HOME_TITLE')
        ->toContain('optional keys read by app config or code. Use --missing to list them.');
});

it('names required keys the default view leaves out', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', preg_replace('/^APP_KEY=.*\R/m', '', (string) file_get_contents($path.'/.env')));

    expect(Artisan::call('env:keys', ['--path' => $path]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Not listed: 1 required key missing from .env (APP_KEY)')
        ->not->toMatch('/\|\s*APP_KEY\s/');
});

it('prints no hint when missing keys are already listed', function () {
    expect(Artisan::call('env:keys', ['--path' => fixtureProject('basic'), '--missing' => true]))->toBe(0);

    expect(Artisan::output())->not->toContain('Not listed:');
});

it('shows who reads each key in a Used In column', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/config/payment.php', "<?php\n\nreturn ['url' => env('PAYMENT_URL')];\n");

    expect(Artisan::call('env:keys', ['--path' => $path]))->toBe(0);
    $lines = explode("\n", Artisan::output());

    $row = fn (string $key): string => array_values(array_filter($lines, fn (string $l) => str_contains($l, "| {$key} ")))[0];

    expect($row('DB_HOST'))->toMatch('/\|\s*app\s*\|\s*used/')
        ->and($row('PAYMENT_URL'))->toMatch('/\|\s*app \(config, code\)\s*\|\s*used/')
        ->and($row('ORPHAN'))->toMatch('/\|\s*\|\s*unused/');
});

it('marks keys read only by code as app (code)', function () {
    expect(Artisan::call('env:keys', ['--path' => fixtureProject('basic')]))->toBe(0);
    $lines = explode("\n", Artisan::output());

    expect(array_values(array_filter($lines, fn (string $l) => str_contains($l, '| PAYMENT_URL ')))[0])
        ->toMatch('/\|\s*app \(code\)\s*\|\s*used/');
});

it('rejects an unknown --only value', function () {
    $this->artisan('env:keys', ['--path' => fixtureProject('basic'), '--only' => 'bogus'])
        ->expectsOutputToContain('Invalid --only value "bogus". Use one of: vendor, missing, unused, sensitive.')
        ->assertExitCode(2);
});

it('masks a connection string with credentials even with --reveal', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nDB_URL=mysql://root:SuperSecret123@localhost/db\n");

    expect(Artisan::call('env:keys', ['--path' => $path, '--reveal' => true]))->toBe(0);

    expect(Artisan::output())->toContain('Mine')->not->toContain('SuperSecret123')->not->toContain('root:');
});
