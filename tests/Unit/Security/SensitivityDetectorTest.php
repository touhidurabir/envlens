<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\Glob;

function defaultDetector(): SensitivityDetector
{
    return new SensitivityDetector(
        config('envlens.sensitive.keys'),
        config('envlens.sensitive.patterns'),
        config('envlens.sensitive.except'),
    );
}

it('flags sensitive keys using the shipped defaults', function (string $key): void {
    expect(defaultDetector()->isSensitive($key))->toBeTrue();
})->with(['APP_KEY', 'STRIPE_SECRET', 'AWS_SECRET_ACCESS_KEY', 'DB_PASSWORD', 'REDIS_PASS', 'GITHUB_TOKEN', 'SENTRY_LARAVEL_DSN', 'stripe_secret', 'GPG_PASSPHRASE', 'FTP_PWD', 'PROXY_AUTH']);

it('does not flag non-sensitive keys using the shipped defaults', function (string $key): void {
    expect(defaultDetector()->isSensitive($key))->toBeFalse();
})->with(['APP_NAME', 'DB_HOST', 'STRIPE_PUBLIC_KEY', 'VITE_PUSHER_APP_KEY']);

it('masks values', function (): void {
    $d = defaultDetector();
    expect($d->mask('secret123'))->toBe('se••••')
        ->and($d->mask(''))->toBe('(empty)')
        ->and($d->mask(null))->toBe('(empty)')
        ->and($d->mask('a'))->toBe('••••')
        ->and($d->mask('abcd'))->toBe('••••')
        ->and($d->mask('abcde'))->toBe('ab••••')
        ->and($d->mask('ñññññ'))->toBe('ññ••••');
});

it('globs case-insensitively and escapes metacharacters', function (): void {
    expect(Glob::matches('*_KEY', 'app_key'))->toBeTrue()
        ->and(Glob::matches('A*', 'A'))->toBeTrue()
        ->and(Glob::matches('a.b', 'aXb'))->toBeFalse()
        ->and(Glob::matches('a.b', 'a.b'))->toBeTrue()
        ->and(Glob::matches('a(b)+', 'a(b)+'))->toBeTrue()
        ->and(Glob::matchesAny(['X', 'Y*'], 'YZ'))->toBeTrue()
        ->and(Glob::matchesAny([], 'YZ'))->toBeFalse();
});

it('treats a key whose value is a connection string with credentials as sensitive', function (): void {
    $d = defaultDetector();

    expect($d->isSensitive('DB_URL', 'mysql://root:SuperSecret123@localhost/db'))->toBeTrue()
        ->and($d->isSensitive('REDIS_URL', 'rediss://:pa55@cache:6379'))->toBeTrue()
        ->and($d->isSensitive('DB_URL', 'mysql://localhost/db'))->toBeFalse()
        ->and($d->isSensitive('APP_URL', 'https://user@example.com'))->toBeFalse()
        ->and($d->isSensitive('DB_URL'))->toBeFalse()
        ->and($d->isSensitive('DB_URL', null))->toBeFalse();
});

it('recognises placeholder values', function (string $value) {
    expect(defaultDetector()->isPlaceholder($value))->toBeTrue();
})->with([
    'null', '(null)', 'NULL', 'empty', '(empty)', 'true', 'false', 'root', 'admin', 'test', 'homestead', 'laravel', 'sail', 'forge',
    '${DB_PASSWORD}', 'mysql://root:${DB_PASSWORD}@db/app', '<token>', 'xxxx', 'XXX',
    'SomeRandomString', 'database_user_password', 'SomeRandomStringOf32CharsExactly', 'secret_firefly_password',
    'PLEASE_REPLACE_WITH_32_CHAR_CODE', 'your-api-key', 'changeme', 'change-me', 'an_example_value', 'placeholder', 'AbCdEfGh123',
]);

it('does not treat real-looking values as placeholders', function (string $value) {
    expect(defaultDetector()->isPlaceholder($value))->toBeFalse();
})->with([
    'sk_live_51Hx9aZ', 'hunter2', 'base64:3q2+7w==', 'whsec_abc123', 'mysql://root:pw@db/app', 'testing123', 'xx', 'rooted',
]);
