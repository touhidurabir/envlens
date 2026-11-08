<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Validation\Inference;
use Touhidurabir\EnvLens\Validation\InferredRule;
use Touhidurabir\EnvLens\Validation\RuleSet;
use Touhidurabir\EnvLens\Validation\RulesFileWriter;

function writtenRules(array $rules): RuleSet
{
    $path = sys_get_temp_dir().'/envlens-generated-'.uniqid().'.php';
    file_put_contents($path, (new RulesFileWriter)->render(new Inference($rules, ['free' => [], 'nonLiteral' => [], 'conflicting' => [], 'none' => []])));

    try {
        return RuleSet::fromFile($path);
    } finally {
        unlink($path);
    }
}

function inferred(string $key, string $assumption = 'because', bool $active = true, string $rule = 'nullable|integer'): InferredRule
{
    return new InferredRule($key, $active ? $rule : 'required', $active, $assumption, 'config/a.php', 5);
}

it('writes a file that loads, with active rules sorted by key', function () {
    $set = writtenRules([inferred('Z_KEY'), inferred('A_KEY', rule: 'nullable|boolean')]);

    expect($set->rulesFor(null))->toBe(['A_KEY' => ['nullable', 'boolean'], 'Z_KEY' => ['nullable', 'integer']])
        ->and($set->infer)->toBeTrue()
        ->and($set->messages)->toBe([]);
});

it('comments out rules a person should decide on', function () {
    expect(writtenRules([inferred('A_KEY'), inferred('MAYBE', active: false)])->rulesFor(null))->toHaveKey('A_KEY')->not->toHaveKey('MAYBE');
});

it('records the assumption and location beside each rule', function () {
    $php = (new RulesFileWriter)->render(new Inference([inferred('A_KEY', 'config default 5 is a whole number'), inferred('MAYBE', 'may be optional', false)], ['free' => [], 'nonLiteral' => [], 'conflicting' => [], 'none' => []]));

    expect($php)->toContain(<<<'PHP'
                // config default 5 is a whole number (config/a.php:5)
                'A_KEY' => [
                    'nullable',
                    'integer',
                ],

                // may be optional (config/a.php:5)
                // 'MAYBE' => [
                //     'required',
                // ],
        PHP)
        ->toContain("// 'environments' => [")
        ->toContain("// 'messages' => [")
        ->not->toContain("'nullable|integer'");
});

it('survives assumptions that could break out of a comment', function (string $assumption) {
    expect(writtenRules([inferred('A_KEY', $assumption), inferred('MAYBE', $assumption, false)])->rulesFor(null))->toHaveKey('A_KEY');
})->with([
    'closing tag' => ['ends ?> <?php exit;'],
    'newline' => ["line\nexit('x');"],
    'carriage return' => ["line\rexit('x');"],
    'quote' => ["it's"],
]);

it('quotes unusual keys safely', function () {
    expect(writtenRules([inferred("A'B")])->rulesFor(null))->toHaveKey("A'B");
});

it('survives a commented-out key that could end the comment', function () {
    expect(writtenRules([inferred('Q2?>KEY', active: false), inferred('A_KEY')])->rulesFor(null))->toHaveKey('A_KEY');
});
