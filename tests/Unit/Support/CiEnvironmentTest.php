<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Support\CiEnvironment;

it('is not CI by default', function () {
    expect(CiEnvironment::isCi())->toBeFalse()->and(CiEnvironment::isGitHubActions())->toBeFalse();
});

it('recognises the CI variable', function (string $value, bool $expected) {
    putenv("CI={$value}");

    expect(CiEnvironment::isCi())->toBe($expected);
})->with([
    ['true', true], ['1', true], ['True', true], ['false', false], ['0', false], ['', false],
]);

it('treats GitHub Actions as CI', function () {
    putenv('GITHUB_ACTIONS=true');

    expect(CiEnvironment::isGitHubActions())->toBeTrue()->and(CiEnvironment::isCi())->toBeTrue();
});
