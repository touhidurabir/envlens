<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function cleanupProject(): string
{
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\nAPP_NAME=Mine\n");
    file_put_contents($path.'/.env.example', "APP_ENV=\n# gone\nEXTRA_ONE=1\nAPP_NAME=\nEXTRA_TWO=2\n");

    return $path;
}

it('removes target-only keys with --force and keeps the rest', function () {
    $path = cleanupProject();

    $this->artisan('env:cleanup', ['--force' => true, '--path' => $path, '--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->toBe("APP_ENV=\nAPP_NAME=\n");
});

it('acts as a dry run non-interactively without --force', function () {
    $path = cleanupProject();
    $before = file_get_contents($path.'/.env.example');

    expect(Artisan::call('env:cleanup', ['--path' => $path, '--no-interaction' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Cleaning .env.example · keys not in .env · 2 to remove · dry run: pass --force to remove')
        ->toMatch('/EXTRA_ONE\s*\|\s*✔\s*\|\s*not in \.env; nothing in the app reads it\s*\|/')
        ->toMatch('/To remove \/ kept\s*\.+\s*2 \/ 0/')
        ->toMatch('/Values lost\s*\.+\s*2/');

    expect(file_get_contents($path.'/.env.example'))->toBe($before);
});

it('removes unused keys from the file given by --file', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\nORPHAN=secret\nVITE_X=1\n");

    $this->artisan('env:cleanup', ['--unused' => true, '--file' => '.env', '--force' => true, '--path' => $path, '--no-interaction' => true])
        ->assertExitCode(0);

    expect(file_get_contents($path.'/.env'))->toBe("APP_ENV=local\nVITE_X=1\n");
});

it('warns about fully dynamic env calls before listing', function () {
    $path = fixtureProject('dynamic');
    file_put_contents($path.'/.env', "ORPHAN=1\nMAIL_HOST=1\n");

    expect(Artisan::call('env:cleanup', ['--unused' => true, '--file' => '.env', '--path' => $path, '--no-interaction' => true]))->toBe(0);
    expect(Artisan::output())->toMatch('/WARN\s+env\(\) with a fully dynamic key at/')
        ->toMatch('/\|\s*ORPHAN\s/')
        ->not->toMatch('/\|\s*MAIL_HOST\s/');
});

it('keeps keys deselected interactively', function () {
    $path = cleanupProject();

    $this->artisan('env:cleanup', ['--path' => $path])
        ->expectsChoice('Remove which keys from .env.example?', ['EXTRA_TWO'], ['EXTRA_ONE' => 'EXTRA_ONE  (has a value)', 'EXTRA_TWO' => 'EXTRA_TWO  (has a value)'])
        ->expectsConfirmation('Remove 1 key from .env.example?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->toContain('EXTRA_ONE=1')->not->toContain('EXTRA_TWO');
});

it('aborts when the confirmation is declined', function () {
    $path = cleanupProject();
    $before = file_get_contents($path.'/.env.example');

    $this->artisan('env:cleanup', ['--path' => $path])
        ->expectsChoice('Remove which keys from .env.example?', ['EXTRA_ONE', 'EXTRA_TWO'], ['EXTRA_ONE' => 'EXTRA_ONE  (has a value)', 'EXTRA_TWO' => 'EXTRA_TWO  (has a value)'])
        ->expectsConfirmation('Remove 2 keys from .env.example?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(1);

    expect(file_get_contents($path.'/.env.example'))->toBe($before);
});

it('reports nothing to clean and rejects the same file', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "A=1\n");
    file_put_contents($path.'/.env.example', "A=\n");

    $this->artisan('env:cleanup', ['--path' => $path])->expectsOutputToContain('Nothing to clean up in .env.example.')->assertExitCode(0);
    $this->artisan('env:cleanup', ['target' => '.env', '--path' => $path])->assertExitCode(2);
});

it('refuses in production without --force but allows --dry-run', function () {
    $this->app['env'] = 'production';
    $path = cleanupProject();
    $before = file_get_contents($path.'/.env.example');

    $this->artisan('env:cleanup', ['--path' => $path, '--no-interaction' => true])->assertExitCode(1);
    $this->artisan('env:cleanup', ['--dry-run' => true, '--path' => $path])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->toBe($before);
});

it('never treats keys read by the framework config as unused', function () {
    $path = fixtureProject('basic');
    mkdir($path.'/vendor/laravel/framework/config', 0777, true);
    file_put_contents($path.'/vendor/laravel/framework/config/hashing.php', "<?php\nreturn ['bcrypt' => ['rounds' => env('BCRYPT_ROUNDS', 12)]];\n");
    file_put_contents($path.'/.env', "APP_ENV=local\nBCRYPT_ROUNDS=12\nORPHAN=1\n");

    expect(Artisan::call('env:cleanup', ['--unused' => true, '--file' => '.env', '--path' => $path, '--no-interaction' => true]))->toBe(0);
    expect(Artisan::output())->toMatch('/\|\s*ORPHAN\s/')->not->toContain('BCRYPT_ROUNDS');
});

it('refuses --unused when no config files were scanned', function () {
    $path = fixtureProject('basic');
    (new Filesystem)->deleteDirectory($path.'/config');
    $before = (string) file_get_contents($path.'/.env');

    $this->artisan('env:cleanup', ['--unused' => true, '--file' => '.env', '--force' => true, '--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('No config files were scanned; refusing to treat every key as unused.')
        ->assertExitCode(2);

    expect(file_get_contents($path.'/.env'))->toBe($before);
});

it('describes --force accurately', function () {
    $option = $this->app->make(Kernel::class)->all()['env:cleanup']->getDefinition()->getOption('force');

    expect($option->getDescription())->toBe('Apply changes (skips the dry-run fallback; required in production)');
});

it('uses one production refusal format in every command', function () {
    $this->app['env'] = 'production';
    $path = cleanupProject();

    $this->artisan('env:cleanup', ['--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to remove keys without --force in production.')
        ->assertExitCode(1);
    $this->artisan('env:sync', ['--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to write without --force in production.')
        ->assertExitCode(1);
    $this->artisan('env:sync', ['--with-values' => true, '--force' => true, '--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to copy values in production.')
        ->assertExitCode(1);
    $this->artisan('env:generate', ['target' => '.env.staging', '--path' => $path])
        ->expectsOutputToContain('Refusing to generate without --force in production.')
        ->assertExitCode(1);
    $this->artisan('env:generate', ['target' => '.env.staging', '--with-values' => true, '--force' => true, '--path' => $path])
        ->expectsOutputToContain('Refusing to copy values in production.')
        ->assertExitCode(1);
    $this->artisan('env:keys', ['--reveal' => true, '--path' => $path])
        ->expectsOutputToContain('Refusing to reveal values in production.')
        ->assertExitCode(1);
});

it('never removes a key the app reads in mirror mode, and says why', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\nAPP_KEY=\nSTALE=\n");

    $this->artisan('env:cleanup', ['--force' => true, '--path' => $path, '--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->toBe("APP_NAME=\nAPP_KEY=\n");
});

it('lists kept keys with their reason', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\nAPP_KEY=\nVITE_X=\nSTALE=\n");

    expect(Artisan::call('env:cleanup', ['--dry-run' => true, '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toMatch('/APP_KEY\s*\.+\s*read by app\.key; missing from \.env/')
        ->toMatch('/VITE_X\s*\.+\s*envlens\.cleanup\.keep/')
        ->toContain('Add it to .env with php artisan env:sync .env.example .env')
        ->toMatch('/\|\s*STALE\s*\|\s*✘\s*\|/');
});

it('keeps keys matched by a runtime-built key in --unused mode', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\nGITHUB_TOKEN=x\nORPHAN=1\n");
    file_put_contents($path.'/config/integrations.php', "<?php\n\n\$p = 'GITHUB';\n\nreturn ['token' => env(\"{\$p}_TOKEN\")];\n");

    expect(Artisan::call('env:cleanup', ['--unused' => true, '--file' => '.env', '--dry-run' => true, '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toMatch('/GITHUB_TOKEN\s*\.+\s*matches \*_TOKEN at config\/integrations\.php:5/')
        ->toContain('1 key has a value in .env; it is lost on removal (a backup is kept).');
});

it('never removes keys from the app env file in production, even with --force', function () {
    $this->app['env'] = 'production';
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\nORPHAN=1\n");

    $this->artisan('env:cleanup', ['--unused' => true, '--file' => '.env', '--force' => true, '--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to remove keys from .env in production.')
        ->assertExitCode(1);

    expect(file_get_contents($path.'/.env'))->toBe("APP_ENV=local\nORPHAN=1\n");
});

it('ends a removal with a DONE badge, a relative backup path and a review hint', function () {
    $path = cleanupProject();

    expect(Artisan::call('env:cleanup', ['--force' => true, '--path' => $path, '--no-interaction' => true]))->toBe(0);

    expect(Artisan::output())->toMatch('/DONE\s+Removed 2 keys from \.env\.example\./')
        ->toContain('Backup: storage/envlens/backups/.env.example.')
        ->toContain('git diff .env.example');
});
