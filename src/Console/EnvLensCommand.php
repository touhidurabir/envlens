<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\EnvLens;
use Touhidurabir\EnvLens\Exceptions\EnvFileNotFound;
use Touhidurabir\EnvLens\Exceptions\ProductionGuardException;
use Touhidurabir\EnvLens\Scanning\UsageIndex;

abstract class EnvLensCommand extends Command
{
    final public function handle(): int
    {
        try {
            $path = $this->input->hasOption('path') ? $this->option('path') : null;

            if (is_string($path) && $path !== '' && ! is_dir($path)) {
                $this->error("Path not found: {$path}");

                return 2;
            }

            return $this->perform();
        } catch (EnvFileNotFound $e) {
            $this->error($e->getMessage());

            return 2;
        } catch (ProductionGuardException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (RuntimeException $e) {
            // Write failures (EnvWriter) leave the original file untouched.
            $this->error($e->getMessage());

            return 1;
        }
    }

    abstract protected function perform(): int;

    protected function lens(): EnvLens
    {
        return $this->laravel->make(EnvLens::class);
    }

    protected function basePath(): string
    {
        $path = $this->input->hasOption('path') ? $this->option('path') : null;

        if (! is_string($path) || $path === '') {
            return realpath(base_path()) ?: base_path();
        }

        return realpath($path) ?: $path;
    }

    protected function envFile(string $relative, bool $mustExist = true): EnvFile
    {
        $path = $this->resolve($relative);

        if (! is_file($path)) {
            if ($mustExist) {
                throw EnvFileNotFound::at($relative);
            }

            return EnvFile::fromString('', $path);
        }

        return EnvFile::fromPath($path);
    }

    /** config('envlens.files.source'), default `.env`. */
    protected function sourceName(): string
    {
        return $this->configString('envlens.files.source', '.env');
    }

    /** config('envlens.files.example'), default `.env.example`. */
    protected function exampleName(): string
    {
        return $this->configString('envlens.files.example', '.env.example');
    }

    /** The argument's value, or $default when it was not given. */
    protected function stringArgument(string $name, string $default): string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** The project's example file (envlens.files.example), or null when it does not exist. */
    protected function exampleFile(): ?EnvFile
    {
        $file = $this->envFile($this->exampleName(), false);

        return $file->path() !== null && is_file($file->path()) ? $file : null;
    }

    /** True when $path is the app's own env file (envlens.files.source), which production never writes. */
    protected function isLiveEnv(string $path): bool
    {
        $live = (string) $this->envFile($this->sourceName(), false)->path();

        return $path === $live || (realpath($path) !== false && realpath($path) === realpath($live));
    }

    /** $path relative to the project base path when it is inside it. */
    protected function relativePath(string $path): string
    {
        $base = $this->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /** Backup directory (envlens.write.backup_path) resolved against the project base path. */
    protected function backupDir(): string
    {
        return $this->resolve($this->configString('envlens.write.backup_path', 'storage/envlens/backups'));
    }

    /** Whether to scan every vendor package and report framework usage in full (--vendor). */
    protected function includesVendor(): bool
    {
        return $this->input->hasOption('vendor') && (bool) $this->option('vendor');
    }

    protected function index(bool $printWarnings = true): UsageIndex
    {
        $index = $this->lens()->usages($this->basePath(), $this->includesVendor());

        if ($printWarnings && ! $this->wantsJson()) {
            foreach ($index->warnings() as $warning) {
                $this->warn($warning);
            }
        }

        return $index;
    }

    protected function json(string $command, mixed $data, int $exit = 0): int
    {
        $this->line((string) json_encode(
            ['schema' => 1, 'command' => $command, 'data' => $data],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return $exit;
    }

    /**
     * Prints the error and returns true when the operation must be refused.
     */
    protected function refuseInProduction(string $what): bool
    {
        try {
            $this->lens()->guard()->ensureAllowed($this->basePath(), $what);
        } catch (ProductionGuardException $e) {
            $this->error($e->getMessage());

            return true;
        }

        return false;
    }

    protected function stringOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    protected function wantsJson(): bool
    {
        return $this->input->hasOption('json') && (bool) $this->option('json');
    }

    private function configString(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function resolve(string $relative): string
    {
        $isAbsolute = str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $relative) === 1;

        return $isAbsolute ? $relative : $this->basePath().DIRECTORY_SEPARATOR.$relative;
    }
}
