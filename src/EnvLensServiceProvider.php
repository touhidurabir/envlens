<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\Console\AuditCommand;
use Touhidurabir\EnvLens\Console\CleanupCommand;
use Touhidurabir\EnvLens\Console\CompareCommand;
use Touhidurabir\EnvLens\Console\ExplainCommand;
use Touhidurabir\EnvLens\Console\GenerateCommand;
use Touhidurabir\EnvLens\Console\KeysCommand;
use Touhidurabir\EnvLens\Console\SyncCommand;
use Touhidurabir\EnvLens\Env\EnvWriter;
use Touhidurabir\EnvLens\Mutation\EnvGenerator;
use Touhidurabir\EnvLens\Mutation\EnvSynchronizer;
use Touhidurabir\EnvLens\Scanning\CodeScanner;
use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\UsageScanner;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\ProductionGuard;

class EnvLensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/envlens.php', 'envlens');

        $this->app->singleton(ConfigScanner::class);
        $this->app->singleton(CodeScanner::class);
        $this->app->singleton(UsageScanner::class, fn (Application $app): UsageScanner => new UsageScanner(
            $app->make(ConfigScanner::class),
            $app->make(CodeScanner::class),
            (array) config('envlens.scan'),
        ));
        $this->app->singleton(SensitivityDetector::class, fn (): SensitivityDetector => new SensitivityDetector(
            $this->stringList('envlens.sensitive.keys'),
            $this->stringList('envlens.sensitive.patterns'),
            $this->stringList('envlens.sensitive.except'),
        ));
        $this->app->singleton(EnvWriter::class, fn (Application $app): EnvWriter => new EnvWriter(
            $app->make(Filesystem::class),
            (array) config('envlens.write'),
        ));
        $this->app->singleton(ProductionGuard::class, fn (Application $app): ProductionGuard => new ProductionGuard(
            $app,
            $this->stringList('envlens.production_environments'),
        ));
        $this->app->singleton(EnvAnalyzer::class, fn (Application $app): EnvAnalyzer => new EnvAnalyzer(
            $app->make(SensitivityDetector::class),
            $this->stringList('envlens.cleanup.keep'),
            $this->stringList('envlens.scan.allow_env_outside_config'),
            $this->stringList('envlens.required'),
        ));
        $this->app->singleton(EnvGenerator::class, fn (Application $app): EnvGenerator => new EnvGenerator(
            $app->make(SensitivityDetector::class),
            $this->stringList('envlens.generate.skip_defaults'),
        ));
        $this->app->singleton(EnvSynchronizer::class, fn (Application $app): EnvSynchronizer => new EnvSynchronizer(
            $app->make(SensitivityDetector::class),
            $this->stringList('envlens.cleanup.keep'),
            $this->stringList('envlens.generate.skip_defaults'),
        ));
        $this->app->singleton(EnvLens::class, fn (Application $app): EnvLens => new EnvLens(
            $app->make(UsageScanner::class),
            $app->make(SensitivityDetector::class),
            $app->make(EnvWriter::class),
            $app->make(ProductionGuard::class),
            $app->make(EnvAnalyzer::class),
            $app->make(EnvGenerator::class),
            $app->make(EnvSynchronizer::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([KeysCommand::class, CompareCommand::class, AuditCommand::class, ExplainCommand::class, GenerateCommand::class, SyncCommand::class, CleanupCommand::class]);

            $this->publishes([
                __DIR__.'/../config/envlens.php' => config_path('envlens.php'),
            ], 'envlens-config');
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        return array_values(array_map(strval(...), (array) config($key)));
    }
}
