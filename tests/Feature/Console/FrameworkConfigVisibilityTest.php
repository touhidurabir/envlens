<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// Laravel's own (unpublished) config is always scanned so keys like BCRYPT_ROUNDS
// count as used, but framework-only keys are not reported unless --vendor.

function frameworkProject(): string
{
    $path = fixtureProject('vendor');
    file_put_contents($path.'/config/app.php', "<?php\n\nreturn ['name' => env('APP_NAME', 'Laravel')];\n");
    file_put_contents($path.'/.env', "APP_NAME=x\nBCRYPT_ROUNDS=10\n");

    return $path;
}

it('shows framework usages for keys in the file but hides framework-only missing keys in env:keys', function () {
    expect(Artisan::call('env:keys', ['--path' => frameworkProject(), '--json' => true]))->toBe(0);
    $rows = array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['rows'], null, 'key');

    expect($rows['BCRYPT_ROUNDS']['status'])->toBe('used')
        ->and($rows['BCRYPT_ROUNDS']['config_paths'])->toBe(['hashing.bcrypt.rounds'])
        ->and($rows)->not->toHaveKey('ARGON_TIME');
});

it('lists vendor keys the file does not define with --vendor, marked as vendor', function () {
    expect(Artisan::call('env:keys', ['--path' => frameworkProject(), '--vendor' => true, '--json' => true]))->toBe(0);
    $rows = array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['rows'], null, 'key');

    expect($rows['ARGON_TIME']['status'])->toBe('missing-with-default')
        ->and($rows['ARGON_TIME']['in_file'])->toBeFalse()
        ->and($rows['ARGON_TIME']['source'])->toBe(['vendor:laravel/framework'])
        ->and($rows['BCRYPT_ROUNDS']['source'])->toBe(['vendor:laravel/framework']);
});

it('shows vendor in the Used In column and a cross for vendor keys the file lacks', function () {
    expect(Artisan::call('env:keys', ['--path' => frameworkProject(), '--vendor' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toMatch('/ARGON_TIME\s*\|\s*✘.*\|\s*vendor\s*\|\s*missing-with-default/')
        ->toMatch('/BCRYPT_ROUNDS\s*\|\s*✔.*\|\s*vendor\s*\|\s*used/')
        ->not->toContain('laravel/framework');
});

it('lists only vendor keys with --only=vendor, in the file or not', function () {
    expect(Artisan::call('env:keys', ['--path' => frameworkProject(), '--only' => 'vendor', '--json' => true]))->toBe(0);
    $keys = array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['rows'], 'key');

    sort($keys);
    expect($keys)->toBe(['ARGON_TIME', 'BCRYPT_ROUNDS', 'WIDGET_TOKEN']); // framework + every other package
});

it('does not list vendor keys under --missing alone', function () {
    expect(Artisan::call('env:keys', ['--path' => frameworkProject(), '--missing' => true]))->toBe(0);

    expect(Artisan::output())->not->toContain('ARGON_TIME');
});

it('keeps framework config out of env:audit unless --vendor', function () {
    $path = frameworkProject();

    Artisan::call('env:audit', ['--path' => $path, '--fail-on' => 'none', '-v' => true]);
    $plain = Artisan::output();

    Artisan::call('env:audit', ['--path' => $path, '--fail-on' => 'none', '--vendor' => true, '-v' => true]);
    $vendor = Artisan::output();

    expect($plain)->not->toContain('ARGON_TIME')
        ->and($vendor)->toMatch('/ARGON_TIME\s*\.+\s*hashing\.argon\.time/');
});

it('still never offers framework-used keys to cleanup --unused', function () {
    $this->artisan('env:cleanup', ['--path' => frameworkProject(), '--unused' => true, '--file' => '.env', '--no-interaction' => true])
        ->doesntExpectOutputToContain('BCRYPT_ROUNDS')
        ->assertExitCode(0);
});

it('folds framework usages that the app config overrides in env:explain unless -v', function () {
    $path = frameworkProject();
    file_put_contents($path.'/config/hashing.php', "<?php\n\nreturn ['bcrypt' => ['rounds' => env('BCRYPT_ROUNDS', 10)]];\n");

    expect(Artisan::call('env:explain', ['key' => 'BCRYPT_ROUNDS', '--path' => $path]))->toBe(0);
    expect(Artisan::output())->toMatch('/hashing\.bcrypt\.rounds\s*\.+\s*config\/hashing\.php:3 · default 10/')
        ->toContain('+1 framework default overridden by your config (-v to list)')
        ->not->toContain('[laravel/framework]');

    expect(Artisan::call('env:explain', ['key' => 'BCRYPT_ROUNDS', '--path' => $path, '-v' => true]))->toBe(0);
    expect(Artisan::output())->toMatch('/\[laravel\/framework\] config\/hashing\.php:\d+ · default \d+ · overridden/');

    expect(Artisan::call('env:explain', ['key' => 'BCRYPT_ROUNDS', '--path' => $path, '--json' => true]))->toBe(0);
    $usages = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['usages'];
    expect(array_column($usages, 'overridden'))->toBe([false, true]);
});
