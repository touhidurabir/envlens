<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('creates a new target with defaults and blanked secrets', function () {
    $path = fixtureProject('basic');

    $this->artisan('env:generate', ['target' => '.env.staging', '--path' => $path])
        ->expectsOutputToContain('Generated .env.staging (10 keys).')
        ->assertExitCode(0);

    $out = (string) file_get_contents($path.'/.env.staging');

    expect($out)->toContain("APP_NAME=Laravel\n")
        ->and($out)->toContain("APP_ENV=\n")
        ->and($out)->toContain("APP_DEBUG=\n")
        ->and($out)->not->toContain('production')
        ->and($out)->toContain("APP_KEY=\n")
        ->and($out)->toContain("DB_PASSWORD=\n")
        ->and($out)->toContain("DB_PORT=3306\n")
        ->and($out)->toContain('# Database')
        ->and($out)->not->toContain('supersecret');
    expect(glob($path.'/storage/envlens/backups/.env.staging.*'))->toBe([]);
});

it('keeps non-sensitive values with --with-values', function () {
    $path = fixtureProject('basic');

    $this->artisan('env:generate', ['target' => '.env.staging', '--with-values' => true, '--path' => $path])->assertExitCode(0);

    $out = (string) file_get_contents($path.'/.env.staging');

    expect($out)->toContain('APP_NAME="My App"')->and($out)->toContain("APP_KEY=\n")->and($out)->not->toContain('supersecret');
});

it('refuses to overwrite non-interactively without --force', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', "KEEP=1\n");

    $this->artisan('env:generate', ['--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('.env.example exists. Use --force to overwrite.')
        ->assertExitCode(1);

    expect(file_get_contents($path.'/.env.example'))->toBe("KEEP=1\n");
});

it('asks before overwriting and prints a tip on no', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', "KEEP=1\n");

    $this->artisan('env:generate', ['--path' => $path])
        ->expectsConfirmation('.env.example exists. Overwrite?', 'no')
        ->expectsOutputToContain('Tip: use env:sync to add only missing keys.')
        ->assertExitCode(1);

    expect(file_get_contents($path.'/.env.example'))->toBe("KEEP=1\n");
});

it('overwrites after confirmation and backs up the old file', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', "KEEP=1\n");

    $this->artisan('env:generate', ['--path' => $path])
        ->expectsConfirmation('.env.example exists. Overwrite?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->toContain('APP_NAME=Laravel')
        ->and(glob($path.'/storage/envlens/backups/.env.example.*'))->toHaveCount(1)
        ->and(is_file($path.'/storage/envlens/backups/.gitignore'))->toBeTrue()
        ->and(glob($path.'/.env.example.*'))->toBe([]);
});

it('overwrites with --force', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', "KEEP=1\n");

    $this->artisan('env:generate', ['--path' => $path, '--force' => true])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->not->toContain('KEEP');
});

it('refuses --with-values in production', function () {
    $this->app['env'] = 'production';
    $path = fixtureProject('basic');

    $this->artisan('env:generate', ['target' => '.env.staging', '--with-values' => true, '--force' => true, '--path' => $path])
        ->assertExitCode(1);

    expect(is_file($path.'/.env.staging'))->toBeFalse();
});

it('refuses in production without --force', function () {
    $this->app['env'] = 'production';
    $path = fixtureProject('basic');

    $this->artisan('env:generate', ['target' => '.env.staging', '--path' => $path])->assertExitCode(1);

    expect(is_file($path.'/.env.staging'))->toBeFalse();
});

it('generates in production with --force and never copies values', function () {
    $this->app['env'] = 'production';
    $path = fixtureProject('basic');

    $this->artisan('env:generate', ['target' => '.env.staging', '--force' => true, '--path' => $path])->assertExitCode(0);

    $out = (string) file_get_contents($path.'/.env.staging');

    expect($out)->toContain('APP_NAME=Laravel')->and($out)->not->toContain('My App')->and($out)->not->toContain('supersecret');
});

it('exits 2 when the source is missing', function () {
    $this->artisan('env:generate', ['--source' => '.env.nope', '--path' => fixtureProject('basic')])->assertExitCode(2);
});

it('exits 2 when source and target are the same file', function () {
    $this->artisan('env:generate', ['target' => '.env', '--force' => true, '--path' => fixtureProject('basic')])->assertExitCode(2);
});

it('drops unparseable lines and reports them', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "A=1\nsecret garbage=hunter2\nB=\"unterminated\n");

    expect(Artisan::call('env:generate', ['target' => '.env.staging', '--path' => $path]))->toBe(0);
    expect(Artisan::output())->toMatch('/Unparseable lines dropped\s*\.+\s*2 \(lines 2, 3\)/')->not->toContain('hunter2');

    expect(file_get_contents($path.'/.env.staging'))->toBe("A=\n");
});

it('never copies a connection string with credentials', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nDB_URL=mysql://root:SuperSecret123@localhost/db\n");

    $this->artisan('env:generate', ['target' => '.env.staging', '--with-values' => true, '--path' => $path])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.staging'))->toBe("APP_NAME=Mine\nDB_URL=\n");
});

it('prints what each key gets and why, and a summary', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', (string) file_get_contents($path.'/.env')."# DB_HOST=10.0.0.5\n");

    expect(Artisan::call('env:generate', ['target' => '.env.staging', '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toContain('Generating .env.staging from .env · 10 keys · creates .env.staging')
        ->toMatch('/APP_NAME\s*\|\s*Laravel\s*\|\s*config default\s*\|/')
        ->toMatch('/APP_KEY\s*\|\s*\(blank\)\s*\|\s*secret: values never copied\s*\|/')
        ->toMatch('/APP_ENV\s*\|\s*\(blank\)\s*\|\s*skipped \(generate\.skip_defaults\)\s*\|/')
        ->toMatch('/Keys written\s*\.+\s*10/')
        ->toMatch('/Commented-out values blanked\s*\.+\s*1/')
        ->toMatch('/DONE\s+Generated \.env\.staging \(10 keys\)\./')
        ->toContain('git diff .env.staging')
        ->not->toContain('10.0.0.5');
});

it('writes nothing with --dry-run, even in production without --force', function () {
    $this->app['env'] = 'production';
    $path = fixtureProject('basic');

    expect(Artisan::call('env:generate', ['target' => '.env.staging', '--dry-run' => true, '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toContain('dry run, nothing written')->not->toContain('DONE');
    expect(is_file($path.'/.env.staging'))->toBeFalse();
});

it('warns what overwriting loses before asking', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env.example', "APP_NAME=Acme\nAPP_KEY=\nAPP_URL=https://acme.test\nLEGACY=1\n");
    file_put_contents($path.'/.env', "APP_NAME=Mine\n");

    expect(Artisan::call('env:generate', ['--dry-run' => true, '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toContain('1 key · overwrites .env.example')
        ->toMatch('/WARN\s+Overwriting \.env\.example loses:/')
        ->toContain('3 keys only in it (APP_KEY (read by the app), APP_URL (read by the app), LEGACY); 1 value set there.')
        ->toContain('php artisan env:sync');
});

it('never overwrites the app env file, even with --force', function () {
    $path = fixtureProject('basic');
    $before = (string) file_get_contents($path.'/.env');

    $this->artisan('env:generate', ['target' => '.env', '--source' => '.env.example', '--force' => true, '--path' => $path])
        ->expectsOutputToContain('Refusing to overwrite .env: a generated file has no real values.')
        ->assertExitCode(1);

    expect(file_get_contents($path.'/.env'))->toBe($before);
});

it('creates a missing app env file outside production, but never in production', function () {
    $path = fixtureProject('basic');
    unlink($path.'/.env');

    $this->artisan('env:generate', ['target' => '.env', '--source' => '.env.example', '--path' => $path])->assertExitCode(0);
    expect(file_get_contents($path.'/.env'))->toContain('APP_NAME=');

    unlink($path.'/.env');
    $this->app['env'] = 'production';

    $this->artisan('env:generate', ['target' => '.env', '--source' => '.env.example', '--force' => true, '--path' => $path])
        ->expectsOutputToContain('Refusing to write .env in production.')
        ->assertExitCode(1);
    expect(is_file($path.'/.env'))->toBeFalse();
});
