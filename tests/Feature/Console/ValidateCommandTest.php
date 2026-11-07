<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function validateCmd(array $options, int $exit = 0): string
{
    expect(Artisan::call('env:validate', $options + ['--no-interaction' => true]))->toBe($exit);

    return Artisan::output();
}

/** A stock Laravel 13 project with a .env that passes every inferred check. */
function validateProject(string $extraEnv = '', ?string $rules = null): string
{
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', (string) file_get_contents($path.'/.env.example')."APP_KEY=base64:abc\n".$extraEnv);

    if ($rules !== null) {
        file_put_contents($path.'/env.rules.php', "<?php\n\nreturn {$rules};\n");
    }

    return $path;
}

it('runs with no rules file, using checks inferred from config', function () {
    $output = validateCmd(['--path' => validateProject()]);

    expect($output)->toMatch('/PASS/')
        ->toContain('Validating .env')
        ->toContain('inferred')
        ->toContain('env:validate --init');
});

it('warns but passes when only an inferred check fails', function () {
    $output = validateCmd(['--path' => validateProject("DB_PORT=not-a-port\n")]);

    expect($output)->toMatch('/WARN/')
        ->toMatch('/DB_PORT\s*\|\s*–\s*\|\s*inferred\s*\|\s*integer/')
        ->toContain('--strict')
        ->not->toMatch('/FAIL/');
});

it('fails on an inferred check with --strict', function () {
    expect(validateCmd(['--path' => validateProject("DB_PORT=not-a-port\n"), '--strict' => true], 1))->toMatch('/FAIL/');
});

it('fails on a rule from the rules file, listing every failure', function () {
    $output = validateCmd(['--path' => validateProject("A=x\nB=y\n", "['rules' => ['A' => 'integer', 'B' => 'integer']]")], 1);

    expect($output)->toMatch('/FAIL/')
        ->toMatch('/\bA\s*\|\s*–\s*\|\s*rules\s*\|\s*integer/')
        ->toMatch('/\bB\s*\|\s*–\s*\|\s*rules\s*\|\s*integer/')
        ->toContain('The A field must be an integer.');
});

it('passes when the rules file is satisfied', function () {
    expect(validateCmd(['--path' => validateProject("A=1\n", "['rules' => ['A' => 'integer']]")]))
        ->toMatch('/PASS/')
        ->toContain('from env.rules.php');
});

it('lets an explicit rule replace the inferred one', function () {
    validateCmd(['--path' => validateProject("DB_PORT=not-a-port\n", "['rules' => ['DB_PORT' => 'string']]"), '--strict' => true]);
});

it('still infers for keys the rules file does not cover', function () {
    expect(validateCmd(['--path' => validateProject("DB_PORT=not-a-port\n", "['rules' => ['A' => 'nullable']]")]))->toMatch('/DB_PORT\s*\|\s*–\s*\|\s*inferred/');
});

it('turns inference off with infer false', function () {
    $output = validateCmd(['--path' => validateProject("DB_PORT=not-a-port\n", "['rules' => ['A' => 'nullable'], 'infer' => false]"), '--strict' => true]);

    expect($output)->toMatch('/PASS/')->not->toContain('DB_PORT');
});

it('adds environment rules, choosing the environment from --environment, then the file', function () {
    $rules = "['rules' => [], 'environments' => ['production' => ['APP_URL' => 'starts_with:https://'], 'staging' => ['APP_URL' => 'integer']]]";
    $path = validateProject("APP_URL=http://a.test\nAPP_ENV=staging\n", $rules);

    expect(validateCmd(['--path' => $path, '--environment' => 'production'], 1))->toContain('starts_with')->toContain('environment: production');
    expect(validateCmd(['--path' => $path], 1))->toContain('integer')->toContain('environment: staging');
});

it('falls back to the running environment', function () {
    $this->app['env'] = 'qa';
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', "A=x\n");
    file_put_contents($path.'/env.rules.php', "<?php\n\nreturn ['rules' => [], 'environments' => ['qa' => ['A' => 'integer']], 'infer' => false];\n");

    expect(validateCmd(['--path' => $path], 1))->toContain('environment: qa');
});

it('reads --file', function () {
    $path = validateProject("A=1\n", "['rules' => ['A' => 'integer'], 'infer' => false]");
    file_put_contents($path.'/.env.staging', "A=x\n");

    expect(validateCmd(['--path' => $path, '--file' => '.env.staging'], 1))->toContain('Validating .env.staging');
});

it('reads the process environment with --from-env, without needing a file', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/env.rules.php', "<?php\n\nreturn ['rules' => ['ENVLENS_T' => 'integer'], 'infer' => false];\n");
    putenv('ENVLENS_T=x');

    try {
        expect(validateCmd(['--path' => $path, '--from-env' => true], 1))->toContain('the process environment');
        putenv('ENVLENS_T=5');
        validateCmd(['--path' => $path, '--from-env' => true]);
    } finally {
        putenv('ENVLENS_T');
    }
});

it('never prints a sensitive value', function () {
    $rules = <<<'PHP'
    ['rules' => ['STRIPE_SECRET' => ['integer', static function ($a, $v, $fail) { $fail("bad {$v}"); }]], 'messages' => ['STRIPE_SECRET.integer' => 'got :input']]
    PHP;
    $path = validateProject("STRIPE_SECRET=sk_live_marker\n", $rules);

    $output = validateCmd(['--path' => $path], 1);
    expect($output)->not->toContain('sk_live_marker')
        ->toMatch('/STRIPE_SECRET\s*\|\s*✔\s*\|\s*rules\s*\|\s*integer, custom rule/');

    expect(validateCmd(['--path' => $path, '--json' => true], 1))->not->toContain('sk_live_marker');

    putenv('GITHUB_ACTIONS=true');
    expect(validateCmd(['--path' => $path], 1))->not->toContain('sk_live_marker');
});

it('outputs JSON', function () {
    $data = json_decode(validateCmd(['--path' => validateProject("A=x\nDB_PORT=nope\n", "['rules' => ['A' => 'integer']]"), '--json' => true], 1), true);

    expect($data['schema'])->toBe(1)
        ->and($data['command'])->toBe('env:validate')
        ->and($data['data']['values_from'])->toBe('.env')
        ->and($data['data']['rules_file'])->toBe('env.rules.php')
        ->and($data['data']['summary'])->toBe(['rules' => 1, 'inferred' => 1])
        ->and(collect($data['data']['failures'])->firstWhere('key', 'A'))->toMatchArray(['source' => 'rules', 'rules' => ['integer']]);
});

it('annotates rule failures as errors and inferred ones as warnings', function () {
    putenv('GITHUB_ACTIONS=true');
    $path = validateProject("A=x\nDB_PORT=nope\n", "['rules' => ['A' => 'integer']]");

    expect(validateCmd(['--path' => $path], 1))
        ->toContain('::error title=env%3Avalidate::A failed integer')
        ->toContain('::warning title=env%3Avalidate::DB_PORT failed integer');

    expect(validateCmd(['--path' => $path, '--strict' => true], 1))->toContain('::error title=env%3Avalidate::DB_PORT failed integer');
});

it('prints no annotation with --json', function () {
    putenv('GITHUB_ACTIONS=true');

    expect(validateCmd(['--path' => validateProject("DB_PORT=nope\n"), '--json' => true]))->not->toContain('::warning');
});

it('exits 2 on usage errors, in one line', function (array $options, string $message) {
    $path = validateProject();

    expect(trim(validateCmd(['--path' => $path] + $options, 2)))->toContain($message)->not->toContain('Stack trace');
})->with([
    'file and from-env' => [['--file' => '.env', '--from-env' => true], 'cannot be combined'],
    'missing env file' => [['--file' => '.env.nope'], 'File not found: .env.nope'],
    'missing named rules file' => [['--rules' => 'nope.php'], 'nope.php'],
]);

it('exits 2 when the rules file is unusable or a rule is unknown', function (string $rules, string $message) {
    expect(validateCmd(['--path' => validateProject('', $rules)], 2))->toContain($message);
})->with([
    'bad shape' => ['5', 'must return an array'],
    'unknown rule' => ["['rules' => ['A' => 'nosuchrule']]", 'Unknown rule for A: nosuchrule'],
]);

it('reports a syntax error and exits 1 without printing the value', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', "A=1\nSECRET_KEY=\"sk_live_marker\n");

    expect(validateCmd(['--path' => $path], 1))->toContain('Syntax error')->not->toContain('sk_live_marker');
    expect(validateCmd(['--path' => $path, '--json' => true], 1))->not->toContain('sk_live_marker');
});

it('is allowed in production', function () {
    $this->app['env'] = 'production';

    validateCmd(['--path' => validateProject()]);
});

it('says when there is nothing to check', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', "A=1\n");
    file_put_contents($path.'/env.rules.php', "<?php\n\nreturn ['rules' => [], 'infer' => false];\n");

    expect(validateCmd(['--path' => $path]))->toContain('Nothing to check');
});
