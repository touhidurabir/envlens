<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Exceptions\InvalidRulesFile;
use Touhidurabir\EnvLens\Validation\RuleSet;

function rulesFile(string $php): string
{
    $path = sys_get_temp_dir().'/envlens-rules-'.uniqid().'.php';
    file_put_contents($path, $php);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

it('loads a rules file', function () {
    $set = RuleSet::fromFile(rulesFile("<?php return ['rules' => ['A' => 'required'], 'messages' => ['A.required' => 'Need A'], 'infer' => false];"));

    expect($set->rulesFor(null))->toBe(['A' => ['required']])
        ->and($set->messages)->toBe(['A.required' => 'Need A'])
        ->and($set->infer)->toBeFalse();
});

it('defaults to inferring, with no messages', function () {
    $set = RuleSet::fromFile(rulesFile("<?php return ['rules' => []];"));

    expect($set->infer)->toBeTrue()->and($set->messages)->toBe([]);
});

it('adds environment rules to the base rules', function () {
    $set = new RuleSet(
        ['APP_URL' => 'required|url', 'A' => ['integer']],
        ['production' => ['APP_URL' => 'starts_with:https://', 'B' => 'required', 'A' => ['min:1']]],
    );

    expect($set->rulesFor('production'))->toBe([
        'APP_URL' => ['required', 'url', 'starts_with:https://'],
        'A' => ['integer', 'min:1'],
        'B' => ['required'],
    ])->and($set->rulesFor('local'))->toBe([
        'APP_URL' => ['required', 'url'],
        'A' => ['integer'],
    ])->and($set->rulesFor(null))->toBe([
        'APP_URL' => ['required', 'url'],
        'A' => ['integer'],
    ]);
});

it('keeps rule objects and closures', function () {
    $closure = static fn () => null;

    expect((new RuleSet(['A' => $closure]))->rulesFor(null)['A'])->toBe([$closure])
        ->and((new RuleSet(['A' => ['required', $closure]]))->rulesFor(null)['A'])->toBe(['required', $closure]);
});

it('rejects a rules file it cannot use, in one line', function (string $php, string $message) {
    try {
        RuleSet::fromFile(rulesFile($php));
        $this->fail('Expected InvalidRulesFile');
    } catch (InvalidRulesFile $e) {
        expect($e->getMessage())->toContain($message)->not->toContain("\n");
    }
})->with([
    'not an array' => ['<?php return 5;', 'must return an array'],
    'no rules' => ["<?php return ['infer' => true];", "'rules'"],
    'rules not an array' => ["<?php return ['rules' => 'x'];", "'rules'"],
    'environments not arrays' => ["<?php return ['rules' => [], 'environments' => ['production' => 'x']];", "'environments'"],
    'messages not an array' => ["<?php return ['rules' => [], 'messages' => 'x'];", "'messages'"],
    'infer not a bool' => ["<?php return ['rules' => [], 'infer' => 'yes'];", "'infer'"],
    'parse error' => ['<?php return [', 'could not be loaded'],
    'throws' => ["<?php throw new Exception('boom');", 'could not be loaded'],
]);

it('never echoes a file passed by mistake', function () {
    ob_start();

    try {
        RuleSet::fromFile(rulesFile("ZED_SECRET=sk_live_marker\n"));
    } catch (InvalidRulesFile) {
        // expected
    } finally {
        $printed = (string) ob_get_clean();
    }

    expect($printed)->toBe('');
});

it('never echoes text before the opening tag of a real rules file', function () {
    ob_start();
    $set = RuleSet::fromFile(rulesFile("<<<<<<< HEAD\n<?php return ['rules' => ['A' => 'required']];"));
    $printed = (string) ob_get_clean();

    expect($printed)->toBe('')->and($set->rulesFor(null))->toHaveKey('A');
});
