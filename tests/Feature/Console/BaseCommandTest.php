<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Artisan;

it('exits 2 when --path does not exist', function () {
    $this->artisan('env:keys', ['--path' => '/definitely/not/here'])
        ->expectsOutputToContain('Path not found: /definitely/not/here')
        ->assertExitCode(2);
});

it('treats any spelling of the app env file as the app env file in production', function (string $command, array $arguments, ?string $existing) {
    $this->app['env'] = 'production';
    $path = fixtureProject('basic');

    if ($existing === null) {
        unlink($path.'/.env');
    }

    // On a case-sensitive filesystem .ENV is its own file, and it has to exist for cleanup to reach the guard.
    if ($existing !== null && ! is_file($path.'/.ENV')) {
        file_put_contents($path.'/.ENV', "APP_NAME=x\n");
    }

    $before = is_file($path.'/.env') ? file_get_contents($path.'/.env') : null;
    $beforeOther = is_file($path.'/.ENV') ? file_get_contents($path.'/.ENV') : null;
    $code = Artisan::call($command, ['--path' => $path, '--force' => true, '--no-interaction' => true] + $arguments);

    expect($code)->toBe(1);
    expect(is_file($path.'/.env') ? file_get_contents($path.'/.env') : null)->toBe($before);
    expect(is_file($path.'/.ENV') ? file_get_contents($path.'/.ENV') : null)->toBe($beforeOther);
})->with([
    'sync, dot slash, absent' => ['env:sync', ['source' => '.env.example', 'target' => './.env'], null],
    'sync, parent segment, absent' => ['env:sync', ['source' => '.env.example', 'target' => 'app/../.env'], null],
    'generate, dot slash, absent' => ['env:generate', ['target' => './.env', '--source' => '.env.example'], null],
    'sync, other case, existing' => ['env:sync', ['source' => '.env.example', 'target' => '.ENV'], 'yes'],
    'cleanup, other case, existing' => ['env:cleanup', ['source' => '.env.example', 'target' => '.ENV'], 'yes'],
]);
