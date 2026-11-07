<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Mutation\ChangeReason;
use Touhidurabir\EnvLens\Mutation\EnvGenerator;
use Touhidurabir\EnvLens\Mutation\GeneratedEnv;
use Touhidurabir\EnvLens\Scanning\DefaultKind;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageSource;
use Touhidurabir\EnvLens\Security\SensitivityDetector;

function generator(): EnvGenerator
{
    return new EnvGenerator(new SensitivityDetector(['APP_KEY'], ['*PASSWORD*', '*SECRET*'], []));
}

function generated(string $env, bool $withValues = false, bool $useDefaults = true): GeneratedEnv
{
    return generator()->generate(EnvFile::fromString($env), analysisIndex(), $withValues, $useDefaults);
}

it('keeps comments and blank lines byte-for-byte', function () {
    $env = "# Application\nAPP_NAME=x\n\n  # indented\nZED=h\n";

    expect(generated($env)->file->toString())->toBe("# Application\nAPP_NAME=Laravel\n\n  # indented\nZED=\n");
});

it('blanks sensitive values', function () {
    expect(generated("APP_KEY=base64:x\nDB_PASSWORD=secret\n", true)->file->toString())->toBe("APP_KEY=\nDB_PASSWORD=\n");
});

it('uses the literal config default by default and blank without defaults', function () {
    expect(generated("DB_PORT=3307\n")->file->toString())->toBe("DB_PORT=3306\n");
    expect(generated("DB_PORT=3307\n", false, false)->file->toString())->toBe("DB_PORT=\n");
});

it('keeps source values with withValues but still blanks sensitive keys', function () {
    expect(generated("APP_NAME=\"My App\"\nAPP_KEY=base64:x\n", true)->file->toString())->toBe("APP_NAME=\"My App\"\nAPP_KEY=\n");
});

it('keeps export, the source EOL and collapses multi-line values', function () {
    expect(generated("export ZED=h\r\nFOO=1\r\n")->file->toString())->toBe("export ZED=\r\nFOO=\r\n");
    expect(generated("MULTI=\"a\nb\"\n", true)->file->toString())->toBe("MULTI=\"a\nb\"\n");
});

it('keeps only the last occurrence of a duplicate key', function () {
    $result = generated("FOO=1\nBAR=2\nFOO=3\n", true);

    expect($result->file->toString())->toBe("BAR=2\nFOO=3\n")->and($result->keys)->toBe(2);
});

it('drops unparseable lines and counts them', function () {
    $result = generated("A=1\nthis is not valid but has secret=hunter2\nB=2\n", true);

    expect($result->file->toString())->toBe("A=1\nB=2\n")
        ->and($result->droppedLines)->toBe(1)
        ->and($result->file->toString())->not->toContain('hunter2');
});

it('writes a key whose value failed to parse as an empty value', function () {
    $result = generated("A=b c\nB=2\n", true);

    expect($result->file->toString())->toBe("A=\nB=2\n")->and($result->droppedLines)->toBe(0);
});

it('writes a key whose value failed to parse as its default', function () {
    expect(generated("DB_PORT=b c\n")->file->toString())->toBe("DB_PORT=3306\n");
});

it('blanks the value of every commented-out assignment and keeps other comments', function () {
    $env = "# Mail settings\n#MAIL_PASSWORD=prod-real-password-123\n# export FOO=bar\n  # APP_URL = https://real.example\r\n";

    expect(generated($env)->file->toString())
        ->toBe("# Mail settings\n#MAIL_PASSWORD=\n# export FOO=\n  # APP_URL=\r\n");
});

it('never fills a config default for keys in generate.skip_defaults', function () {
    $generator = new EnvGenerator(new SensitivityDetector([], [], []), ['APP_ENV', 'APP_DEBUG']);
    $out = $generator->generate(EnvFile::fromString("APP_ENV=local\nAPP_DEBUG=true\nAPP_NAME=x\n"), analysisIndex(), false, true);
    $copied = $generator->generate(EnvFile::fromString("APP_ENV=local\n"), analysisIndex(), true, true);

    expect($out->file->toString())->toBe("APP_ENV=\nAPP_DEBUG=\nAPP_NAME=Laravel\n")
        ->and($copied->file->toString())->toBe("APP_ENV=local\n");
});

it('blanks commented-out secrets behind any comment marker', function (string $comment, string $expected) {
    expect(generated($comment."\n")->file->toString())->toBe($expected."\n");
})->with([
    ['## STRIPE_SECRET=sk_live_x', '## STRIPE_SECRET='],
    ['#; STRIPE_SECRET=sk_live_x', '#; STRIPE_SECRET='],
    ['#- STRIPE_SECRET=sk_live_x', '#- STRIPE_SECRET='],
]);

it('blanks secrets inside a prose comment and leaves the rest of the comment alone', function () {
    $result = generated("# Old: STRIPE_SECRET=sk_live_x until March, TIMEOUT=30 is fine\n");

    expect($result->file->toString())->toBe("# Old: STRIPE_SECRET= until March, TIMEOUT=30 is fine\n")
        ->and($result->blankedComments)->toBe(1);
});

it('does not write a config default that holds credentials', function () {
    $index = new UsageIndex([
        new EnvUsage('DB_URL', 'config/a.php', 1, UsageSource::Config, 'a.b', DefaultKind::Literal, 'mysql://u:hunter2@h/db', null),
    ]);
    $result = generator()->generate(EnvFile::fromString("DB_URL=x\n"), $index, false, true);

    expect($result->file->toString())->toBe("DB_URL=\n")
        ->and($result->changes[0]->reason)->toBe(ChangeReason::Secret);
});
