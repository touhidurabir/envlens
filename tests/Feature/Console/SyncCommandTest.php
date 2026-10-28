<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function syncProject(string $target = "APP_ENV=local\n"): string
{
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\nNEW_FLAG=hello\nNEW_KEY=zzz\nAPP_NAME=Mine\nDB_PORT=3306\nSTRIPE_SECRET=sk_live_x\nAPI_TOKEN=tok_y\n");
    file_put_contents($path.'/.env.example', $target);

    return $path;
}

it('prints the plan on --dry-run and leaves the target untouched', function () {
    $path = syncProject();

    expect(Artisan::call('env:sync', ['--dry-run' => true, '--path' => $path]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Syncing .env → .env.example · 6 keys to add · dry run, nothing written')
        ->toMatch('/NEW_KEY\s*\|\s*\(blank\)\s*\|\s*secret: values never copied\s*\|/')
        ->toMatch('/NEW_FLAG\s*\|\s*\(blank\)\s*\|\s*no literal config default\s*\|/')
        ->toMatch('/DB_PORT\s*\|\s*3306\s*\|\s*config default\s*\|/')
        ->not->toContain('sk_live_x')->not->toContain('DONE');

    expect(file_get_contents($path.'/.env.example'))->toBe("APP_ENV=local\n");
});

it('writes blanks and defaults non-interactively and keeps existing lines', function () {
    $path = syncProject("# keep me\nAPP_ENV=local\n");

    $this->artisan('env:sync', ['--path' => $path, '--no-interaction' => true])->assertExitCode(0);

    $out = (string) file_get_contents($path.'/.env.example');

    expect($out)->toStartWith("# keep me\nAPP_ENV=local\n")
        ->and($out)->toContain("NEW_FLAG=\n")->and($out)->toContain("DB_PORT=3306\n")
        ->and($out)->toContain("STRIPE_SECRET=\n")->and($out)->not->toContain('sk_live_x')->and($out)->not->toContain('hello');
});

it('copies non-sensitive values with --with-values non-interactively', function () {
    $path = syncProject();

    expect(Artisan::call('env:sync', ['--with-values' => true, '--path' => $path, '--no-interaction' => true]))->toBe(0);
    expect(Artisan::output())->toMatch('/NEW_FLAG\s*\|\s*hello\s*\|\s*value copied\s*\|/')
        ->toMatch('/Value copied \/ config default \/ blank\s*\.+\s*3 \/ 0 \/ 3/')
        ->toMatch('/Secrets \(always blank\)\s*\.+\s*3/')
        ->toMatch('/DONE\s+Added 6 keys to \.env\.example\./')
        ->toContain('git diff .env.example')
        ->not->toContain('tok_y');

    $out = (string) file_get_contents($path.'/.env.example');

    expect($out)->toContain('NEW_FLAG=hello')->and($out)->toContain("API_TOKEN=\n")->and($out)->not->toContain('tok_y');
});

it('copies only the chosen keys interactively', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\nNEW_FLAG=hello\nAPP_NAME=Mine\nSTRIPE_SECRET=sk_live_x\n");
    file_put_contents($path.'/.env.example', "APP_ENV=local\n");

    $this->artisan('env:sync', ['--path' => $path])
        ->expectsChoice('Copy values for which keys? (secrets are never copied)', ['APP_NAME'], ['NEW_FLAG' => 'NEW_FLAG  hello', 'APP_NAME' => 'APP_NAME  Mine'])
        ->expectsConfirmation('Write 3 keys to .env.example?', 'yes')
        ->assertExitCode(0);

    $out = (string) file_get_contents($path.'/.env.example');

    expect($out)->toContain('APP_NAME=Mine')->and($out)->toContain("NEW_FLAG=\n")
        ->and($out)->toContain("STRIPE_SECRET=\n")->and($out)->not->toContain('sk_live_x');
});

it('creates a missing target', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "A=1\n");

    $this->artisan('env:sync', ['target' => '.env.new', '--path' => $path, '--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.new'))->toContain("A=\n");
});

it('reports when the target already has every key', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "A=1\n");
    file_put_contents($path.'/.env.example', "A=\n");

    $this->artisan('env:sync', ['--path' => $path])
        ->expectsOutputToContain('.env.example already has every key in .env; nothing to add.')
        ->assertExitCode(0);
});

it('exits 2 for the same file and for a missing source', function () {
    $path = fixtureProject('basic');

    $this->artisan('env:sync', ['target' => '.env', '--path' => $path])->assertExitCode(2);
    $this->artisan('env:sync', ['source' => '.env.nope', '--path' => $path])->assertExitCode(2);
});

it('refuses to write in production without --force', function () {
    $this->app['env'] = 'production';
    $path = syncProject();

    $this->artisan('env:sync', ['--path' => $path, '--no-interaction' => true])->assertExitCode(1);

    expect(file_get_contents($path.'/.env.example'))->toBe("APP_ENV=local\n");
});

it('refuses --with-values in production even with --force', function () {
    $this->app['env'] = 'production';
    $path = syncProject();

    $this->artisan('env:sync', ['--with-values' => true, '--force' => true, '--path' => $path, '--no-interaction' => true])->assertExitCode(1);

    expect(file_get_contents($path.'/.env.example'))->toBe("APP_ENV=local\n");
});

it('writes in production with --force and copies no values', function () {
    $this->app['env'] = 'production';
    $path = syncProject();

    $this->artisan('env:sync', ['--force' => true, '--path' => $path, '--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->not->toContain('hello');
});

it('never copies a connection string with credentials', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nDB_URL=mysql://root:SuperSecret123@localhost/db\n");
    file_put_contents($path.'/.env.example', "APP_ENV=local\n");

    $this->artisan('env:sync', ['--with-values' => true, '--path' => $path, '--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->toContain("DB_URL=\n")->toContain('APP_NAME=Mine')
        ->not->toContain('SuperSecret123');
});

it('writes the backup to envlens.write.backup_path under the project', function () {
    $path = syncProject();
    config(['envlens.write.backup_path' => 'var/env-backups']);

    $this->artisan('env:sync', ['--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Backup: var/env-backups/.env.example.')
        ->assertExitCode(0);

    expect(glob($path.'/var/env-backups/.env.example.*'))->toHaveCount(1)
        ->and(file_get_contents($path.'/var/env-backups/.gitignore'))->toBe("*\n!.gitignore\n");
});

it('leaves APP_ENV and APP_DEBUG blank instead of the config default', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\nAPP_DEBUG=true\n");
    file_put_contents($path.'/.env.example', '');

    $this->artisan('env:sync', ['--path' => $path, '--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents($path.'/.env.example'))->toContain("APP_ENV=\nAPP_DEBUG=\n")->not->toContain('production');
});

it('exits 1 with the writer message when the write fails', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Running as root.');
    }

    $path = syncProject();
    chmod($path, 0555);

    try {
        $this->artisan('env:sync', ['--path' => $path, '--no-interaction' => true])
            ->expectsOutputToContain('envlens: could not create temporary file')
            ->assertExitCode(1);
    } finally {
        chmod($path, 0755);
    }

    expect(file_get_contents($path.'/.env.example'))->toBe("APP_ENV=local\n");
});

it('says which keys only the target has and where they belong', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nNEW_FLAG=1\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\nAPP_KEY=\nSTALE=\n");

    expect(Artisan::call('env:sync', ['--dry-run' => true, '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toContain('Not touched: 2 keys only in .env.example.')
        ->toContain('APP_KEY: read by the app but missing from .env; add it with php artisan env:sync .env.example .env.')
        ->toContain('STALE: nothing in the app reads it; remove with php artisan env:cleanup .env .env.example.');
});

it('says it creates a missing target and why APP_ENV stays blank', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_ENV=local\n");

    expect(Artisan::call('env:sync', ['target' => '.env.new', '--dry-run' => true, '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toContain('1 key to add · creates .env.new · dry run, nothing written')
        ->toMatch('/APP_ENV\s*\|\s*\(blank\)\s*\|\s*skipped \(generate\.skip_defaults\)/');
});

it('treats webhook URLs as secrets', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T/B/X\n");
    file_put_contents($path.'/.env.example', '');

    expect(Artisan::call('env:sync', ['--with-values' => true, '--path' => $path, '--no-interaction' => true]))->toBe(0);

    expect(file_get_contents($path.'/.env.example'))->toContain("SLACK_WEBHOOK_URL=\n")->not->toContain('hooks.slack.com');
});

it('syncs the other way, from the example into .env', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\nNEW_FLAG=on\n");

    expect(Artisan::call('env:sync', ['source' => '.env.example', 'target' => '.env', '--path' => $path, '--no-interaction' => true]))->toBe(0);

    expect(Artisan::output())->toContain('Syncing .env.example → .env')->not->toContain('git diff');
    expect(file_get_contents($path.'/.env'))->toContain("APP_NAME=Mine\n")->toContain('NEW_FLAG=');
});

it('never writes to the app env file in production, even with --force', function () {
    $this->app['env'] = 'production';
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\nNEW_FLAG=\n");

    $this->artisan('env:sync', ['source' => '.env.example', 'target' => '.env', '--force' => true, '--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Refusing to write to .env in production.')
        ->assertExitCode(1);

    expect(file_get_contents($path.'/.env'))->toBe("APP_NAME=Mine\n");

    $this->artisan('env:sync', ['source' => '.env.example', 'target' => '.env', '--dry-run' => true, '--path' => $path])
        ->assertExitCode(0);
});

it('never suggests deleting keys from the app env file', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\nORPHAN=1\nGITHUB_TOKEN=x\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\nNEW_FLAG=\n");
    file_put_contents($path.'/config/integrations.php', "<?php\n\n\$p = 'GITHUB';\n\nreturn ['token' => env(\"{\$p}_TOKEN\")];\n");

    expect(Artisan::call('env:sync', ['source' => '.env.example', 'target' => '.env', '--dry-run' => true, '--path' => $path]))->toBe(0);

    expect(Artisan::output())->toContain('GITHUB_TOKEN: read by the app but missing from .env.example')
        ->toContain('ORPHAN: nothing in the app reads it; check with php artisan env:explain KEY before removing anything by hand.')
        ->not->toContain('env:cleanup');
});

it('still lists target-only keys when there is nothing to add', function () {
    $path = fixtureProject('basic');
    file_put_contents($path.'/.env', "APP_NAME=Mine\n");
    file_put_contents($path.'/.env.example', "APP_NAME=\nAPP_KEY=\n");

    expect(Artisan::call('env:sync', ['--path' => $path, '--no-interaction' => true]))->toBe(0);

    expect(Artisan::output())->toContain('.env.example already has every key in .env; nothing to add.')
        ->toContain('APP_KEY: read by the app but missing from .env; add it with php artisan env:sync .env.example .env.');
});
