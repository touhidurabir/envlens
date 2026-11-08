<?php

declare(strict_types=1);

/**
 * Runs a command in a real PHP process with no terminal and stdin closed, as a git hook or a pipeline
 * would. Unit tests can't reach this: inside them, commands always count as having a terminal.
 *
 * @return array{0: int, 1: string}
 */
function withoutTerminal(string $path, string ...$arguments): array
{
    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
    $script = tempnam(sys_get_temp_dir(), 'envlens-run');
    file_put_contents($script, <<<PHP
    <?php
    require '{$autoload}';
    \$app = Orchestra\Testbench\Foundation\Application::create(null, null, ['load_environment_variables' => false, 'extra' => ['providers' => [Touhidurabir\EnvLens\EnvLensServiceProvider::class]]]);
    \$app['env'] = 'local';
    exit(\$app->make(Illuminate\Contracts\Console\Kernel::class)->handle(new Symfony\Component\Console\Input\ArgvInput, new Symfony\Component\Console\Output\ConsoleOutput));
    PHP);

    $command = [PHP_BINARY, $script, ...$arguments, '--path='.$path];
    $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH'), 'PAO_DISABLE' => '1']);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $code = proc_close($process);
    unlink($script);

    return [$code, (string) $output];
}

function noTerminalProject(): string
{
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/.env', (string) file_get_contents($path.'/.env.example')."APP_KEY=base64:abc\n");

    return $path;
}

it('runs env:validate with no terminal, without asking', function () {
    [$code, $output] = withoutTerminal(noTerminalProject(), 'env:validate');

    expect($code)->toBe(0)->and($output)->toContain('inferred')->not->toContain('How do you want');
});

it('treats env:cleanup with no terminal as a dry run', function () {
    $path = noTerminalProject();
    file_put_contents($path.'/.env.example', "\nORPHAN_KEY=1\n", FILE_APPEND);
    $before = file_get_contents($path.'/.env.example');

    [$code, $output] = withoutTerminal($path, 'env:cleanup');

    expect($code)->toBe(0)->and($output)->not->toContain('Aborted')
        ->and(file_get_contents($path.'/.env.example'))->toBe($before);
});

it('exits 2 from env:explain with no key and no terminal', function () {
    [$code] = withoutTerminal(noTerminalProject(), 'env:explain');

    expect($code)->toBe(2);
});
