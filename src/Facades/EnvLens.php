<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Facades;

use Illuminate\Support\Facades\Facade;
use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Env\EnvWriter;
use Touhidurabir\EnvLens\EnvLens as EnvLensManager;
use Touhidurabir\EnvLens\Mutation\EnvGenerator;
use Touhidurabir\EnvLens\Mutation\EnvSynchronizer;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\ProductionGuard;

/**
 * @method static EnvFile file(string $path)
 * @method static UsageIndex usages(?string $basePath = null, bool $includeVendor = false)
 * @method static SensitivityDetector sensitivity()
 * @method static EnvWriter writer()
 * @method static ProductionGuard guard()
 * @method static EnvAnalyzer analyzer()
 * @method static EnvGenerator generator()
 * @method static EnvSynchronizer synchronizer()
 *
 * @see EnvLensManager
 */
final class EnvLens extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EnvLensManager::class;
    }
}
