<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Env\EnvEntryType;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\EnvFileNotFound;

it('round-trips byte-identical', function (string $source): void {
    expect(EnvFile::fromString($source)->toString())->toBe($source);
})->with([
    'LF' => "A=1\nB=2\n",
    'CRLF' => "A=1\r\nB=2\r\n",
    'no trailing newline' => "A=1\nB=2",
    'multi-line value' => "A=\"a\nb\"\nC=3\n",
    'export' => "export FOO=bar\n",
    'inline comment' => "FOO=bar # comment\n",
    'variable' => "URL=\${APP_URL}/x\n",
    'empty' => "EMPTY=\n",
    'single quoted' => "Q='single # not comment'\n",
    'comments and blanks' => "# c\n\n  \nA=1\n",
    'invalid' => "NOT A LINE\nB=\"open\n",
    'empty file' => '',
]);

it('parses values', function (): void {
    expect(EnvFile::fromString("FOO=bar # comment\n")->get('FOO'))->toBe('bar')
        ->and(EnvFile::fromString("M=\"line1\nline2\"\n")->get('M'))->toBe("line1\nline2")
        ->and(EnvFile::fromString("URL=\${APP_URL}/x\n")->get('URL'))->toBe('${APP_URL}/x')
        ->and(EnvFile::fromString("EMPTY=\n")->get('EMPTY'))->toBe('')
        ->and(EnvFile::fromString("Q='single # not comment'\n")->get('Q'))->toBe('single # not comment')
        ->and(EnvFile::fromString("export FOO=bar\n")->get('FOO'))->toBe('bar')
        ->and(EnvFile::fromString("export FOO=bar\n")->lines()[0]->exported)->toBeTrue();
});

it('lists keys in first-seen order, unique', function (): void {
    $file = EnvFile::fromString("B=1\nA=2\nB=3\n");

    expect($file->keys())->toBe(['B', 'A'])
        ->and($file->has('A'))->toBeTrue()
        ->and($file->has('Z'))->toBeFalse()
        ->and($file->isEmpty('A'))->toBeFalse();
});

it('reports duplicates and last definition wins', function (): void {
    $file = EnvFile::fromString("A=1\nB=2\nA=3");

    expect($file->duplicates())->toBe(['A' => [1, 3]])
        ->and($file->get('A'))->toBe('3');
});

it('tracks start lines across multi-line values', function (): void {
    $file = EnvFile::fromString("A=\"x\ny\"\nB=2\n");

    expect($file->lines())->toHaveCount(2)
        ->and($file->lines()[1]->line)->toBe(3);
});

it('reports syntax errors', function (): void {
    $file = EnvFile::fromString("A=1\nB=\"unterminated\n");

    expect($file->syntaxErrors())->toHaveCount(1)
        ->and($file->syntaxErrors()[0]->line)->toBe(2)
        ->and($file->lines()[1]->type)->toBe(EnvEntryType::Invalid);

    $bad = EnvFile::fromString('NOT A LINE');
    expect($bad->lines()[0]->type)->toBe(EnvEntryType::Invalid)
        ->and($bad->syntaxErrors())->toHaveCount(1);
});

it('detects the eol', function (): void {
    expect(EnvFile::fromString("A=1\r\nB=2\r\n")->eol())->toBe("\r\n")
        ->and(EnvFile::fromString("A=1\nB=2\n")->eol())->toBe("\n")
        ->and(EnvFile::fromString('A=1')->eol())->toBe("\n");
});

it('throws when the file is missing', function (): void {
    EnvFile::fromPath('/nope/.env');
})->throws(EnvFileNotFound::class, 'File not found: /nope/.env');

it('reads from a path and rebuilds with withLines', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'envlens');
    file_put_contents($path, "A=1\nB=2\n");

    $file = EnvFile::fromPath($path);
    $kept = array_values(array_filter($file->lines(), fn ($l) => $l->key !== 'A'));
    $next = $file->withLines($kept);
    unlink($path);

    expect($file->path())->toBe($path)
        ->and($next->toString())->toBe("B=2\n")
        ->and($next->path())->toBe($path);
});

it('does not let single-quoted values span lines', function (): void {
    $source = "MSG='oops\nX='y'\n";
    $file = EnvFile::fromString($source);

    expect($file->toString())->toBe($source)
        ->and($file->has('X'))->toBeTrue()
        ->and($file->get('X'))->toBe('y')
        ->and($file->get('MSG'))->toBeNull()
        ->and($file->syntaxErrors())->toHaveCount(1)
        ->and($file->syntaxErrors()[0]->line)->toBe(1);
});

it('treats an unparseable unquoted value as a null Key with a syntax error', function (): void {
    $file = EnvFile::fromString("A=foo bar\n");

    expect($file->lines()[0]->type)->toBe(EnvEntryType::Key)
        ->and($file->lines()[0]->value)->toBeNull()
        ->and($file->syntaxErrors())->toHaveCount(1)
        ->and($file->isEmpty('A'))->toBeTrue();
});

it('records a syntax error for an escaped quote in single quotes and keeps lexing', function (): void {
    $source = "A='it\\'s'\nB=2\n";
    $file = EnvFile::fromString($source);

    expect($file->toString())->toBe($source)
        ->and($file->syntaxErrors())->toHaveCount(1)
        ->and($file->get('B'))->toBe('2');
});

it('normalises CRLF inside a multi-line double-quoted value', function (): void {
    expect(EnvFile::fromString("A=\"a\r\nb\"\r\n")->get('A'))->toBe("a\nb");
});

it('never puts value text in a syntax error message', function (): void {
    $errors = EnvFile::fromString("DB_PASSWORD=hunter2 secret value\nA=\"x\\q\"\nB='unterminated\n")->syntaxErrors();

    expect($errors)->toHaveCount(3);

    foreach ($errors as $error) {
        expect($error->message)->not->toContain('hunter2')
            ->not->toContain('secret value')
            ->not->toContain('unterminated')
            ->not->toContain('x\\q')
            ->not->toContain('[');
    }

    expect($errors[0]->message)->toBe('Failed to parse dotenv file. Encountered unexpected whitespace.');
});
