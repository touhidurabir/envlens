<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Env;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Touhidurabir\EnvLens\Mutation\Change;
use Touhidurabir\EnvLens\Mutation\Plan;

final class EnvWriter
{
    /**
     * @param  array<string, mixed>  $options  config('envlens.write')
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly array $options = [],
    ) {}

    public function apply(EnvFile $file, Plan $plan): EnvFile
    {
        if ($plan->isEmpty()) {
            return $file;
        }

        $eol = $file->eol();
        $lines = $file->lines();

        foreach ($plan->removes() as $change) {
            $lines = $this->remove($lines, $change->key);
        }

        $appended = false;

        foreach ($plan->adds() as $change) {
            $lines = $this->add($lines, $change, $eol, $appended);
        }

        return $file->withLines($lines);
    }

    /**
     * Returns the backup path, or null when no backup was made.
     *
     * Paths are joined with `/`, which PHP accepts on every OS, so returned paths read the same everywhere.
     * Backups go to $backupDir, or else to the `backup_path` option (default
     * `storage/envlens/backups`) resolved against the directory of $path. The directory is
     * created private (0700) with a `.gitignore` that ignores everything in it. A backup is
     * named `<basename>.<YmdHis>`, with `-N` appended when that name is already taken.
     *
     * @throws RuntimeException when any step of the write fails; the original file is left untouched.
     */
    public function write(string $path, EnvFile $file, ?string $backupDir = null): ?string
    {
        // Write through a symlinked .env (shared-file deploys) instead of replacing the link.
        $target = is_link($path) ? (realpath($path) ?: $path) : $path;
        $exists = is_file($target);
        $mode = $exists ? (fileperms($target) & 0777) : (0666 & ~umask());
        $dir = dirname($target);

        if (! $exists) {
            $this->files->ensureDirectoryExists($dir);
        }

        $contents = $file->toString();
        $temp = $dir.'/.envlens-'.bin2hex(random_bytes(6)).'.tmp';
        $backup = null;

        try {
            $this->createRestricted($temp, $mode, $contents, "temporary file for [{$path}]");

            if ($exists && $this->option('backup', true)) {
                $existing = @file_get_contents($target);

                if ($existing === false) {
                    throw new RuntimeException("envlens: could not read [{$path}] to back it up.");
                }

                $backup = $this->backupPath($path, $backupDir);
                $this->createRestricted($backup, $mode, $existing, "backup [{$backup}]");
            }

            // Windows cannot replace a file flagged read-only; clear the flag first. The new file
            // already carries the original mode, so the flag is back once it is in place.
            $readOnly = PHP_OS_FAMILY === 'Windows' && $exists && ! is_writable($target);
            if ($readOnly) {
                @chmod($target, 0666);
            }

            if (! @rename($temp, $target)) {
                if ($readOnly) {
                    @chmod($target, $mode);
                }

                throw new RuntimeException("envlens: could not replace [{$path}].");
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        return $backup;
    }

    private function backupPath(string $path, ?string $backupDir): string
    {
        if ($backupDir === null) {
            $configured = $this->options['backup_path'] ?? null;
            $configured = is_string($configured) && $configured !== '' ? $configured : 'storage/envlens/backups';
            $isAbsolute = str_starts_with($configured, '/') || str_starts_with($configured, '\\')
                || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1;
            $backupDir = $isAbsolute ? $configured : dirname($path).'/'.$configured;
        }

        $backupDir = rtrim($backupDir, '/\\');
        $this->ensureBackupDir($backupDir);

        $base = $backupDir.'/'.basename($path).'.'.now()->format('YmdHis');
        $candidate = $base;

        for ($n = 1; file_exists($candidate); $n++) {
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    private function ensureBackupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException("envlens: could not create backup directory [{$dir}].");
            }

            @chmod($dir, 0700);
        }

        $ignore = $dir.'/.gitignore';

        if (! is_file($ignore) && @file_put_contents($ignore, "*\n!.gitignore\n") === false) {
            throw new RuntimeException("envlens: could not write [{$ignore}].");
        }
    }

    /** Create the file private (0600), fill it, and only then apply the final mode. */
    private function createRestricted(string $file, int $mode, string $contents, string $what): void
    {
        if (@file_put_contents($file, '') === false || ! @chmod($file, 0600)) {
            throw new RuntimeException("envlens: could not create {$what}.");
        }

        if (@file_put_contents($file, $contents) !== strlen($contents)) {
            throw new RuntimeException("envlens: could not write {$what}.");
        }

        if (! @chmod($file, $mode)) {
            throw new RuntimeException("envlens: could not set permissions on {$what}.");
        }
    }

    public static function formatValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('/[\s#="\']|\$\{/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    /**
     * @param  list<EnvLine>  $lines
     * @return list<EnvLine>
     */
    private function remove(array $lines, string $key): array
    {
        $drop = [];

        foreach ($lines as $i => $line) {
            if ($line->type !== EnvEntryType::Key || $line->key !== $key) {
                continue;
            }

            $drop[$i] = true;

            if ($this->option('remove_attached_comments', true)) {
                for ($j = $i - 1; $j >= 0 && $lines[$j]->type === EnvEntryType::Comment; $j--) {
                    $drop[$j] = true;
                }
            }
        }

        return array_values(array_filter($lines, static fn (int $i): bool => ! isset($drop[$i]), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @param  list<EnvLine>  $lines
     * @return list<EnvLine>
     */
    private function add(array $lines, Change $change, string $eol, bool &$appended): array
    {
        $new = $this->keyLine($change, $eol);
        $at = $this->option('group_by_prefix', true) ? $this->lastWithPrefix($lines, $change->key) : null;

        if ($at !== null) {
            $lines = $this->terminate($lines, $at, $eol);
            array_splice($lines, $at + 1, 0, [$new]);

            return $lines;
        }

        if ($lines !== []) {
            $lines = $this->terminate($lines, count($lines) - 1, $eol);
        }

        if (! $appended && $this->option('header_comment', true)) {
            if ($lines !== [] && $lines[count($lines) - 1]->type !== EnvEntryType::Blank) {
                $lines[] = new EnvLine(EnvEntryType::Blank, $eol, 0);
            }

            $lines[] = new EnvLine(EnvEntryType::Comment, '# Added by envlens '.now()->format('Y-m-d').$eol, 0);
        }

        $appended = true;
        $lines[] = $new;

        return $lines;
    }

    private function keyLine(Change $change, string $eol): EnvLine
    {
        $value = self::formatValue($change->value);

        return new EnvLine(EnvEntryType::Key, $change->key.'='.$value.$eol, 0, $change->key, $change->value);
    }

    /**
     * Ensure the line at $index ends with a newline so something can follow it.
     *
     * @param  list<EnvLine>  $lines
     * @return list<EnvLine>
     */
    private function terminate(array $lines, int $index, string $eol): array
    {
        $line = $lines[$index];

        if (! str_ends_with($line->raw, "\n")) {
            array_splice($lines, $index, 1, [new EnvLine($line->type, $line->raw.$eol, $line->line, $line->key, $line->value, $line->exported)]);
        }

        return $lines;
    }

    /**
     * @param  list<EnvLine>  $lines
     */
    private function lastWithPrefix(array $lines, string $key): ?int
    {
        $prefix = $this->prefix($key);

        if ($prefix === null) {
            return null;
        }

        $found = null;

        foreach ($lines as $i => $line) {
            if ($line->type === EnvEntryType::Key && $line->key !== null && $this->prefix($line->key) === $prefix) {
                $found = $i;
            }
        }

        return $found;
    }

    private function prefix(string $key): ?string
    {
        $pos = strpos($key, '_');

        return $pos === false || $pos === 0 ? null : substr($key, 0, $pos);
    }

    private function option(string $name, bool $default): bool
    {
        return (bool) ($this->options[$name] ?? $default);
    }
}
