<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Exceptions\ProductionGuardException;
use Touhidurabir\EnvLens\Support\ProductionGuard;

function tempProject(?string $env): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'envlens-guard-'.str_replace('.', '', uniqid('', true));
    mkdir($dir);
    $GLOBALS['envLensTempDirs'][] = $dir;
    if ($env !== null) {
        file_put_contents($dir.'/.env', $env);
    }

    return $dir;
}

it('is production when the app environment is production', function (): void {
    $this->app['env'] = 'production';
    expect((new ProductionGuard($this->app, ['production']))->isProduction(tempProject(null)))->toBeTrue();
});

it('reads APP_ENV from the project .env', function (?string $env, bool $expected): void {
    expect((new ProductionGuard($this->app, ['production']))->isProduction(tempProject($env)))->toBe($expected);
})->with([
    'production' => ["APP_ENV=production\n", true],
    'local' => ["APP_ENV=local\n", false],
    'missing file' => [null, false],
    'case sensitive' => ["APP_ENV=Production\n", false],
]);

it('supports custom environments', function (): void {
    expect((new ProductionGuard($this->app, ['prod', 'live']))->isProduction(tempProject("APP_ENV=live\n")))->toBeTrue();
});

it('refuses in production', function (): void {
    $guard = new ProductionGuard($this->app, ['production']);
    $guard->ensureAllowed(tempProject("APP_ENV=local\n"), 'write');
    $guard->ensureAllowed(tempProject("APP_ENV=production\n"), 'write');
})->throws(ProductionGuardException::class, 'Refusing to write in production.');
