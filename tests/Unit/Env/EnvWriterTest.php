<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Env\EnvWriter;
use Touhidurabir\EnvLens\Mutation\Change;
use Touhidurabir\EnvLens\Mutation\ChangeType;
use Touhidurabir\EnvLens\Mutation\Plan;

function writer(array $options = []): EnvWriter
{
    return new EnvWriter(new Filesystem, $options + ['backup' => true, 'group_by_prefix' => true, 'header_comment' => true, 'remove_attached_comments' => true]);
}

function addPlan(string $key, ?string $value = null): Plan
{
    return new Plan([new Change(ChangeType::Add, $key, $value)]);
}

afterEach(fn () => Carbon::setTestNow());

it('leaves the file identical for an empty plan', function (): void {
    $src = "A=1\r\n# c\nB=\"x\ny\"";

    expect(writer()->apply(EnvFile::fromString($src), new Plan([]))->toString())->toBe($src);
});

it('inserts after the last key sharing the prefix', function (): void {
    $out = writer()->apply(EnvFile::fromString("APP=1\nMAIL_HOST=h\nDB=2\n"), addPlan('MAIL_FROM', 'x'));

    expect($out->toString())->toBe("APP=1\nMAIL_HOST=h\nMAIL_FROM=x\nDB=2\n");
});

it('appends with a header when there is no prefix match', function (): void {
    Carbon::setTestNow('2026-10-27');

    $out = writer()->apply(EnvFile::fromString('A=1'), addPlan('NEW_KEY'));

    expect($out->toString())->toBe("A=1\n\n# Added by envlens 2026-10-27\nNEW_KEY=\n");
});

it('adds the header only once', function (): void {
    Carbon::setTestNow('2026-10-27');
    $plan = new Plan([new Change(ChangeType::Add, 'X_ONE'), new Change(ChangeType::Add, 'Y_TWO')]);

    expect(writer()->apply(EnvFile::fromString("A=1\n"), $plan)->toString())
        ->toBe("A=1\n\n# Added by envlens 2026-10-27\nX_ONE=\nY_TWO=\n");
});

it('skips the header and grouping when disabled', function (): void {
    $out = writer(['header_comment' => false, 'group_by_prefix' => false])
        ->apply(EnvFile::fromString("MAIL_HOST=h\nDB=2\n"), addPlan('MAIL_FROM'));

    expect($out->toString())->toBe("MAIL_HOST=h\nDB=2\nMAIL_FROM=\n");
});

it('uses CRLF for CRLF files', function (): void {
    $out = writer()->apply(EnvFile::fromString("MAIL_HOST=h\r\nDB=2\r\n"), addPlan('MAIL_FROM', 'x'));

    expect($out->toString())->toBe("MAIL_HOST=h\r\nMAIL_FROM=x\r\nDB=2\r\n");
});

it('removes a key and its attached comments', function (): void {
    $plan = new Plan([new Change(ChangeType::Remove, 'B')]);
    $src = "A=1\n# about b\nB=2\n\nC=3\n";

    expect(writer()->apply(EnvFile::fromString($src), $plan)->toString())->toBe("A=1\n\nC=3\n")
        ->and(writer(['remove_attached_comments' => false])->apply(EnvFile::fromString($src), $plan)->toString())->toBe("A=1\n# about b\n\nC=3\n");
});

it('formats values', function (): void {
    expect(EnvWriter::formatValue('hello world'))->toBe('"hello world"')
        ->and(EnvWriter::formatValue('a"b'))->toBe('"a\"b"')
        ->and(EnvWriter::formatValue('plain'))->toBe('plain')
        ->and(EnvWriter::formatValue(null))->toBe('')
        ->and(EnvWriter::formatValue("a\nb"))->toBe("\"a\nb\"")
        ->and(EnvWriter::formatValue('${X}'))->toBe('"${X}"')
        ->and(EnvWriter::formatValue('a\\b c'))->toBe('"a\\\\b c"');
});

it('round-trips an added multi-line value', function (): void {
    $out = writer()->apply(EnvFile::fromString("A=1\n"), addPlan('M_K', "l1\nl2"));

    expect($out->get('M_K'))->toBe("l1\nl2");
});

it('writes atomically with a backup', function (): void {
    Carbon::setTestNow('2026-10-27 00:00:00');
    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "OLD=1\n");

    $backup = writer()->write("$dir/.env", EnvFile::fromString("NEW=2\n"));
    clearstatcache();

    expect($backup)->toBe("$dir/storage/envlens/backups/.env.20261027000000")
        ->and(file_get_contents($backup))->toBe("OLD=1\n")
        ->and(file_get_contents("$dir/.env"))->toBe("NEW=2\n")
        ->and(file_get_contents("$dir/storage/envlens/backups/.gitignore"))->toBe("*\n!.gitignore\n")
        ->and(fileperms("$dir/storage/envlens/backups") & 0777)->toBe(0700);

    (new Filesystem)->deleteDirectory($dir);
});

it('makes no backup when disabled or when the file is new', function (): void {
    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "OLD=1\n");

    expect(writer(['backup' => false])->write("$dir/.env", EnvFile::fromString("NEW=2\n")))->toBeNull()
        ->and(is_dir("$dir/storage/envlens/backups"))->toBeFalse()
        ->and(writer()->write("$dir/.env.new", EnvFile::fromString("X=1\n")))->toBeNull()
        ->and(file_get_contents("$dir/.env.new"))->toBe("X=1\n");

    (new Filesystem)->deleteDirectory($dir);
});

it('never serializes values in a plan', function (): void {
    $json = (new Plan([new Change(ChangeType::Add, 'K', 'secret', true)]))->jsonSerialize();

    expect($json)->toBe([['type' => 'add', 'key' => 'K', 'value_copied' => true]]);
});

it('omits the leading blank line for an empty file', function (): void {
    Carbon::setTestNow('2026-10-27');

    expect(writer()->apply(EnvFile::fromString(''), addPlan('NEW_KEY'))->toString())
        ->toBe("# Added by envlens 2026-10-27\nNEW_KEY=\n");
});

it('omits the leading blank line when the file already ends blank', function (): void {
    Carbon::setTestNow('2026-10-27');

    expect(writer()->apply(EnvFile::fromString("A=1\n\n"), addPlan('NEW_KEY'))->toString())
        ->toBe("A=1\n\n# Added by envlens 2026-10-27\nNEW_KEY=\n");
});

it('preserves file permissions on the target and the backup', function (): void {
    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "OLD=1\n");
    chmod("$dir/.env", 0600);

    $backup = writer()->write("$dir/.env", EnvFile::fromString("NEW=2\n"));
    clearstatcache();

    expect(fileperms("$dir/.env") & 0777)->toBe(0600)
        ->and(fileperms($backup) & 0777)->toBe(0600)
        ->and(glob("$dir/.envlens-*"))->toBe([]);

    (new Filesystem)->deleteDirectory($dir);
});

it('throws and leaves the original untouched when the directory is read-only', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Running as root.');
    }

    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "OLD=1\n");
    chmod($dir, 0555);

    try {
        expect(fn () => writer()->write("$dir/.env", EnvFile::fromString("NEW=2\n")))->toThrow(RuntimeException::class)
            ->and(file_get_contents("$dir/.env"))->toBe("OLD=1\n");
    } finally {
        chmod($dir, 0755);
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('writes through a symlinked env file', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symlinks unavailable.');
    }

    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/shared.env", "OLD=1\n");
    symlink("$dir/shared.env", "$dir/.env");

    $backup = writer()->write("$dir/.env", EnvFile::fromString("NEW=2\n"));

    expect(is_link("$dir/.env"))->toBeTrue()
        ->and(file_get_contents("$dir/shared.env"))->toBe("NEW=2\n")
        ->and($backup)->toStartWith("$dir/storage/envlens/backups/.env.")
        ->and(file_get_contents($backup))->toBe("OLD=1\n");

    (new Filesystem)->deleteDirectory($dir);
});

it('can write a read-only target and keeps its mode', function (): void {
    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "OLD=1\n");
    chmod("$dir/.env", 0400);

    $backup = writer()->write("$dir/.env", EnvFile::fromString("NEW=2\n"));
    clearstatcache();

    expect(file_get_contents("$dir/.env"))->toBe("NEW=2\n")
        ->and(fileperms("$dir/.env") & 0777)->toBe(0400)
        ->and(fileperms($backup) & 0777)->toBe(0400)
        ->and(file_get_contents($backup))->toBe("OLD=1\n");

    (new Filesystem)->deleteDirectory($dir);
});

it('writes backups to the given directory, or to an absolute backup_path', function (): void {
    Carbon::setTestNow('2026-10-27 00:00:00');
    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "OLD=1\n");

    $given = writer()->write("$dir/.env", EnvFile::fromString("NEW=2\n"), "$dir/backups-here");
    $absolute = writer(['backup_path' => "$dir/abs"])->write("$dir/.env", EnvFile::fromString("NEW=3\n"));

    expect($given)->toBe("$dir/backups-here/.env.20261027000000")
        ->and(file_get_contents($given))->toBe("OLD=1\n")
        ->and(is_file("$dir/backups-here/.gitignore"))->toBeTrue()
        ->and($absolute)->toBe("$dir/abs/.env.20261027000000")
        ->and(file_get_contents($absolute))->toBe("NEW=2\n");

    (new Filesystem)->deleteDirectory($dir);
});

it('never overwrites a backup made in the same second', function (): void {
    Carbon::setTestNow('2026-10-27 00:00:00');
    $dir = sys_get_temp_dir().'/envlens-w-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "V=1\n");
    chmod("$dir/.env", 0640);

    $first = writer()->write("$dir/.env", EnvFile::fromString("V=2\n"));
    $second = writer()->write("$dir/.env", EnvFile::fromString("V=3\n"));
    $third = writer()->write("$dir/.env", EnvFile::fromString("V=4\n"));
    clearstatcache();

    expect($first)->toBe("$dir/storage/envlens/backups/.env.20261027000000")
        ->and($second)->toBe("$dir/storage/envlens/backups/.env.20261027000000-1")
        ->and($third)->toBe("$dir/storage/envlens/backups/.env.20261027000000-2")
        ->and(file_get_contents($first))->toBe("V=1\n")
        ->and(file_get_contents($second))->toBe("V=2\n")
        ->and(file_get_contents($third))->toBe("V=3\n")
        ->and(fileperms($second) & 0777)->toBe(0640)
        ->and(fileperms("$dir/.env") & 0777)->toBe(0640);

    (new Filesystem)->deleteDirectory($dir);
});
