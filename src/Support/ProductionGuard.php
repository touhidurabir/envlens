<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Support;

use Illuminate\Contracts\Foundation\Application;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\EnvFileNotFound;
use Touhidurabir\EnvLens\Exceptions\ProductionGuardException;
use Touhidurabir\EnvLens\Scanning\UsageScanner;

final class ProductionGuard
{
    /**
     * @param  list<string>  $environments
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $environments,
        private readonly string $sourceFile = '.env',
    ) {}

    public function isProduction(string $basePath): bool
    {
        // environment() with no arguments returns the name, which is always truthy.
        if ($this->environments !== [] && $this->app->environment(...$this->environments)) {
            return true;
        }

        $source = UsageScanner::isAbsolute($this->sourceFile) ? $this->sourceFile : rtrim($basePath, '/\\').'/'.$this->sourceFile;

        try {
            $env = EnvFile::fromPath($source)->get('APP_ENV');
        } catch (EnvFileNotFound) {
            return false;
        }

        return $env !== null && in_array($env, $this->environments, true);
    }

    public function ensureAllowed(string $basePath, string $what): void
    {
        if ($this->isProduction($basePath)) {
            throw ProductionGuardException::refusing($what);
        }
    }
}
