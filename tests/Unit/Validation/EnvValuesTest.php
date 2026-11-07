<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Validation\EnvValues;

it('returns what env() would', function (string $line, mixed $expected) {
    expect(EnvValues::fromFile(EnvFile::fromString("X={$line}\n"))['X'])->toBe($expected);
})->with([
    ['true', true], ['(true)', true], ['TRUE', true],
    ['false', false], ['(false)', false],
    ['null', null], ['(null)', null],
    ['empty', ''], ['(empty)', ''],
    ['', ''],
    ['plain', 'plain'],
    ['"double"', 'double'],
    ["'single'", 'single'],
    ['"null"', null],
    ['3306', '3306'],
]);

it('resolves references to other keys in the same file', function () {
    $values = EnvValues::fromFile(EnvFile::fromString("BASE=https://a.test\nAPI=\"\${BASE}/api\"\n"));

    expect($values['API'])->toBe('https://a.test/api');
});

it('reads the process environment as env() does', function () {
    putenv('ENVLENS_TEST_ONE=(true)');
    putenv('ENVLENS_TEST_TWO=plain');

    try {
        $values = EnvValues::fromProcess();

        expect($values['ENVLENS_TEST_ONE'])->toBeTrue()
            ->and($values['ENVLENS_TEST_TWO'])->toBe('plain')
            ->and($values)->not->toHaveKey('argv')
            ->and($values)->not->toHaveKey('ENVLENS_TEST_ABSENT');
    } finally {
        putenv('ENVLENS_TEST_ONE');
        putenv('ENVLENS_TEST_TWO');
    }
});
