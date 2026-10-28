<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens;

use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Env\EnvWriter;
use Touhidurabir\EnvLens\Mutation\EnvGenerator;
use Touhidurabir\EnvLens\Mutation\EnvSynchronizer;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageScanner;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\ProductionGuard;

final class EnvLens
{
    public function __construct(
        private readonly UsageScanner $scanner,
        private readonly SensitivityDetector $sensitivity,
        private readonly EnvWriter $writer,
        private readonly ProductionGuard $guard,
        private readonly EnvAnalyzer $analyzer,
        private readonly EnvGenerator $generator,
        private readonly EnvSynchronizer $synchronizer,
    ) {}

    public function file(string $path): EnvFile
    {
        return EnvFile::fromPath($path);
    }

    public function usages(?string $basePath = null, bool $includeVendor = false): UsageIndex
    {
        return $this->scanner->scan($basePath ?? base_path(), $includeVendor);
    }

    public function sensitivity(): SensitivityDetector
    {
        return $this->sensitivity;
    }

    public function writer(): EnvWriter
    {
        return $this->writer;
    }

    public function guard(): ProductionGuard
    {
        return $this->guard;
    }

    public function analyzer(): EnvAnalyzer
    {
        return $this->analyzer;
    }

    public function generator(): EnvGenerator
    {
        return $this->generator;
    }

    public function synchronizer(): EnvSynchronizer
    {
        return $this->synchronizer;
    }
}
