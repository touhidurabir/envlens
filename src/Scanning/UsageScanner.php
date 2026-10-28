<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

final class UsageScanner
{
    /** Read by Laravel's own (unpublished) config files, so always scanned. */
    private const FRAMEWORK_PACKAGE = 'laravel/framework';

    /**
     * @param  array<string, mixed>  $scanConfig  shape of config('envlens.scan')
     */
    public function __construct(
        private readonly ConfigScanner $config,
        private readonly CodeScanner $code,
        private readonly array $scanConfig,
    ) {}

    public function scan(string $basePath, bool $includeVendor = false): UsageIndex
    {
        $usages = [];
        $warnings = [];
        $base = rtrim($basePath, '/\\');
        $configFiles = 0;
        $configPaths = $this->list('config_paths');

        foreach ($configPaths as $dir) {
            $result = $this->config->scan($basePath, self::isAbsolute($dir) ? $dir : $base.'/'.ltrim($dir, '/\\'));
            $configFiles += $result['files'];
            array_push($usages, ...$result['usages']);
            array_push($warnings, ...$result['warnings']);
        }

        if ($configFiles === 0) {
            $warnings[] = 'No config files were scanned (scan.config_paths: '.implode(', ', $configPaths).'); every key will look unused.';
        }

        $result = $this->code->scan($basePath, $this->list('code_paths'), $this->list('exclude'));
        array_push($usages, ...$result['usages']);
        array_push($warnings, ...$result['warnings']);

        $framework = $base.'/vendor/'.self::FRAMEWORK_PACKAGE.'/config';
        $vendorDirs = is_dir($framework) ? [self::FRAMEWORK_PACKAGE => $framework] : [];

        if ($includeVendor) {
            $dirs = glob($base.'/vendor/*/*/config', GLOB_ONLYDIR) ?: [];
            sort($dirs);
            foreach ($dirs as $dir) {
                $parts = explode('/', str_replace('\\', '/', $dir));
                $package = $parts[count($parts) - 3].'/'.$parts[count($parts) - 2];
                $vendorDirs[$package] ??= $dir;
            }
        }

        foreach ($vendorDirs as $package => $dir) {
            $result = $this->config->scan($basePath, $dir, UsageSource::Vendor, $package);
            array_push($usages, ...$result['usages']);
            array_push($warnings, ...$result['warnings']);
        }

        // Laravel's own config is always scanned so framework keys never look unused,
        // but it is only reported in full when vendor scanning was asked for.
        return new UsageIndex($usages, $warnings, $includeVendor ? [] : [self::FRAMEWORK_PACKAGE]);
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        $value = $this->scanConfig[$key] ?? [];

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
