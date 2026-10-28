<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Env\EnvFile;

it('compares two files', function () {
    $r = analyzer()->compare(
        EnvFile::fromString("A=1\nB=2\nC=\n"),
        EnvFile::fromString("A=9\nD=4\nC=\n"),
        analysisIndex(),
        EnvFile::fromString("APP_KEY=\n"),
    );

    expect($r->onlyInSource)->toBe(['B'])
        ->and($r->onlyInTarget)->toBe(['D'])
        ->and($r->different)->toBe(['A'])
        ->and($r->emptyInTarget)->toBe([])
        ->and($r->missingFromBoth)->toBe(['APP_KEY']);
});

it('lists only declared required keys as missing from both', function () {
    $source = EnvFile::fromString("A=1\n");
    $target = EnvFile::fromString("A=2\n");

    expect(analyzer()->compare($source, $target, analysisIndex())->missingFromBoth)->toBe([])
        ->and(analyzer(required: ['X'])->compare($source, $target, analysisIndex())->missingFromBoth)->toBe(['X']);
});

it('reports keys empty in target but set in source', function () {
    $r = analyzer()->compare(EnvFile::fromString("B=2\n"), EnvFile::fromString("B=\n"), analysisIndex());

    expect($r->emptyInTarget)->toBe(['B'])->and($r->different)->toBe([]);
});

it('does not report keys empty in source', function () {
    $r = analyzer()->compare(EnvFile::fromString("B=\n"), EnvFile::fromString("B=2\n"), analysisIndex());

    expect($r->emptyInTarget)->toBe([]);
});
