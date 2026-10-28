<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Support;

use Illuminate\Contracts\Foundation\Application;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\EnvFileNotFound;
use Touhidurabir\EnvLens\Exceptions\ProductionGuardException;

final class ProductionGuard
{
    /**
     * @param  list<string>  $environments
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $environments,
    ) {}

    public function isProduction(string $basePath): bool
    {
        if ($this->app->environment(...$this->environments)) {
            return true;
        }

        try {
            $env = EnvFile::fromPath(rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.'.env')->get('APP_ENV');
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
