<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
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

const VALIDATE_PROMPT = 'No env.rules.php found. How do you want to validate .env?';
const VALIDATE_CHOICES = [
    'infer' => 'Run checks inferred from your config (writes nothing)',
    'generate' => 'Generate env.rules.php from your config and review it',
];

it('asks what to do when there is no rules file', function () {
    $this->artisan('env:validate', ['--path' => validateProject()])
        ->expectsChoice(VALIDATE_PROMPT, 'infer', VALIDATE_CHOICES)
        ->expectsOutputToContain('-n')
        ->assertExitCode(0);
});

it('writes nothing when told to run the inferred checks', function () {
    $path = validateProject();

    $this->artisan('env:validate', ['--path' => $path])->expectsChoice(VALIDATE_PROMPT, 'infer', VALIDATE_CHOICES)->assertExitCode(0);

    expect(file_exists($path.'/env.rules.php'))->toBeFalse();
});

it('generates the rules file when asked, then validates with it', function () {
    $path = validateProject("DB_PORT=not-a-port\n");

    $this->artisan('env:validate', ['--path' => $path])
        ->expectsChoice(VALIDATE_PROMPT, 'generate', VALIDATE_CHOICES)
        ->expectsConfirmation('Write env.rules.php?', 'yes')
        ->expectsOutputToContain('Validating .env')
        ->assertExitCode(1);

    expect(file_get_contents($path.'/env.rules.php'))->toContain("'DB_PORT' => 'nullable|integer'");
});

it('stops without validating when the generated file is declined', function () {
    $path = validateProject();

    $this->artisan('env:validate', ['--path' => $path])
        ->expectsChoice(VALIDATE_PROMPT, 'generate', VALIDATE_CHOICES)
        ->expectsConfirmation('Write env.rules.php?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(1);

    expect(file_exists($path.'/env.rules.php'))->toBeFalse();
});

it('never asks when a rules file exists or one is named', function () {
    $path = validateProject('', "['rules' => []]");

    $this->artisan('env:validate', ['--path' => $path])->assertExitCode(0);
    $this->artisan('env:validate', ['--path' => $path, '--rules' => 'env.rules.php'])->assertExitCode(0);
});

it('never asks on a CI system', function (string $variable) {
    putenv($variable);

    $this->artisan('env:validate', ['--path' => validateProject()])->assertExitCode(0);
})->with(['CI=true', 'CI=1', 'GITHUB_ACTIONS=true']);

it('never asks with --json or --no-interaction', function (string $option) {
    $this->artisan('env:validate', ['--path' => validateProject(), $option => true])->assertExitCode(0);
})->with(['--json', '--no-interaction']);

it('never asks in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('env:validate', ['--path' => validateProject()])->assertExitCode(0);
});

it('shows every rule and its assumption before writing with --init', function () {
    $path = validateProject();
    $output = validateCmd(['--path' => $path, '--init' => true]);

    expect($output)->toContain('Generating env.rules.php from your config')
        ->toMatch('/DB_PORT\s*\|\s*nullable\|integer\s*\|\s*config default 3306 is a whole number\s*\|\s*config\/database\.php:\d+/')
        ->toMatch('/APP_KEY\s*\|\s*required\s*\|\s*Laravel cannot encrypt without it/')
        ->toMatch('/AWS_BUCKET\s*\|\s*required \(commented out\)\s*\|\s*no default, and declared required; may be optional/')
        ->toMatch('/Active rules \/ commented out\s*\.+\s*28 \/ 8/')
        ->toMatch('/No rule: free-text \/ non-literal \/ conflicting default\s*\.+\s*\d+ \/ \d+ \/ \d+/')
        ->toMatch('/No rule: no default and not declared\s*\.+\s*\d+/')
        ->toContain('DONE')
        ->toContain('git add env.rules.php');

    $php = (string) file_get_contents($path.'/env.rules.php');
    expect($php)->toContain("// 'AWS_BUCKET' => 'required',")->toContain("'infer' => true,");

    expect(validateCmd(['--path' => $path]))->toMatch('/PASS/')->toContain('from env.rules.php');
});

it('lists keys with no rule only with -v', function () {
    $path = validateProject();

    expect(validateCmd(['--path' => $path, '--init' => true, '--dry-run' => true]))->not->toContain('LOG_CHANNEL');

    expect(Artisan::call('env:validate', ['--path' => $path, '--init' => true, '--dry-run' => true, '-v' => true, '--no-interaction' => true]))->toBe(0);
    expect(Artisan::output())->toMatch('/Free-text default.*LOG_CHANNEL/s');
});

it('writes nothing with --dry-run, even when the file exists', function () {
    $path = validateProject();

    expect(validateCmd(['--path' => $path, '--init' => true, '--dry-run' => true]))->toContain('dry run, nothing written')->not->toContain('DONE');
    expect(file_exists($path.'/env.rules.php'))->toBeFalse();

    file_put_contents($path.'/env.rules.php', "<?php\n\nreturn ['rules' => []];\n");
    validateCmd(['--path' => $path, '--init' => true, '--dry-run' => true]);
    expect(file_get_contents($path.'/env.rules.php'))->toBe("<?php\n\nreturn ['rules' => []];\n");
});

it('exits 2 for --dry-run without --init', function () {
    expect(validateCmd(['--path' => validateProject(), '--dry-run' => true], 2))->toContain('--dry-run needs --init');
});

it('never overwrites an existing rules file', function () {
    $path = validateProject('', "['rules' => ['A' => 'integer']]");
    $before = file_get_contents($path.'/env.rules.php');

    expect(validateCmd(['--path' => $path, '--init' => true], 1))->toContain('already exists')->toContain('--dry-run');
    expect(file_get_contents($path.'/env.rules.php'))->toBe($before);
});

it('refuses to generate in production, but allows a dry run', function () {
    $this->app['env'] = 'production';
    $path = validateProject();

    expect(validateCmd(['--path' => $path, '--init' => true], 1))->toContain('production');
    expect(file_exists($path.'/env.rules.php'))->toBeFalse();

    validateCmd(['--path' => $path, '--init' => true, '--dry-run' => true]);
});

it('asks before writing with --init, defaulting to yes, and writes without asking when not interactive', function () {
    $path = validateProject();

    $this->artisan('env:validate', ['--path' => $path, '--init' => true])
        ->expectsConfirmation('Write env.rules.php?', 'no')
        ->assertExitCode(1);
    expect(file_exists($path.'/env.rules.php'))->toBeFalse();

    $this->artisan('env:validate', ['--path' => $path, '--init' => true])
        ->expectsConfirmation('Write env.rules.php?', 'yes')
        ->assertExitCode(0);
    expect(file_exists($path.'/env.rules.php'))->toBeTrue();
});

it('writes to the path named by --rules', function () {
    $path = validateProject();

    validateCmd(['--path' => $path, '--init' => true, '--rules' => 'custom.rules.php']);

    expect(file_exists($path.'/custom.rules.php'))->toBeTrue()->and(file_exists($path.'/env.rules.php'))->toBeFalse();
});

it('never shows a sensitive default in the table or the file', function () {
    $path = validateProject();
    file_put_contents($path.'/config/partner.php', "<?php\n\nreturn ['s' => env('PARTNER_SECRET', 123456)];\n");

    expect(validateCmd(['--path' => $path, '--init' => true]))->not->toContain('123456')->toContain('config default is a whole number');
    expect(file_get_contents($path.'/env.rules.php'))->not->toContain('123456');
});

it('never prints a secret when phpdotenv rejects a file the lexer accepted', function (array $options) {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', "API_SECRET=abc=\"sk_live_marker\nOTHER=\"y z\"\n");

    expect(validateCmd(['--path' => $path] + $options, 1))->toContain('could not be parsed')->not->toContain('sk_live_marker');
})->with(['text' => [[]], 'json' => [['--json' => true]]]);

it('exits 2 rather than pass when there are no rules and no config to infer from', function (array $options) {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', "DB_PORT=abc\n");
    (new Filesystem)->deleteDirectory($path.'/config');

    expect(validateCmd(['--path' => $path] + $options, 2))->toContain('scan.config_paths');
    expect(file_exists($path.'/env.rules.php'))->toBeFalse();
})->with([
    'plain' => [[]],
    'strict' => [['--strict' => true]],
    'json' => [['--json' => true]],
    'init' => [['--init' => true]],
]);

it('still runs a rules file when there is no config to infer from', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', "A=x\n");
    file_put_contents($path.'/env.rules.php', "<?php\n\nreturn ['rules' => ['A' => 'integer']];\n");
    (new Filesystem)->deleteDirectory($path.'/config');

    validateCmd(['--path' => $path], 1);
});

it('says how many checks passed, not how many failed, when all pass', function () {
    expect(validateCmd(['--path' => validateProject()]))->toMatch('/PASS\s+36 checks passed/');
});

it('outputs the generated rules as JSON', function (array $options) {
    $path = validateProject();
    $data = json_decode(validateCmd(['--path' => $path, '--init' => true, '--json' => true] + $options), true);

    expect($data['schema'])->toBe(1)
        ->and($data['command'])->toBe('env:validate')
        ->and(collect($data['data']['rules'])->firstWhere('key', 'DB_PORT'))->toMatchArray(['written' => 'nullable|integer', 'active' => true])
        ->and($data['data']['skipped'])->toHaveKeys(['free', 'nonLiteral', 'conflicting', 'none'])
        ->and($data['data']['file'])->toBe('env.rules.php');
})->with(['dry run' => [['--dry-run' => true]], 'writing' => [[]]]);
