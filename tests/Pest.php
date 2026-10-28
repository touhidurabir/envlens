<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Touhidurabir\EnvLens\Analysis\AuditReport;
use Touhidurabir\EnvLens\Analysis\EnvAnalyzer;
use Touhidurabir\EnvLens\Analysis\Finding;
use Touhidurabir\EnvLens\Analysis\FindingCode;
use Touhidurabir\EnvLens\Analysis\KeyStatus;
use Touhidurabir\EnvLens\Scanning\CodeScanner;
use Touhidurabir\EnvLens\Scanning\ConfigScanner;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageScanner;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/** @var list<string> $envLensTempDirs */
$GLOBALS['envLensTempDirs'] = [];

/**
 * Copy tests/Fixtures/projects/<name> into a fresh temp dir and return its absolute path.
 * The directory is removed after each test.
 */
function fixtureProject(string $name): string
{
    $source = __DIR__.'/Fixtures/projects/'.$name;

    if (! is_dir($source)) {
        throw new InvalidArgumentException("Fixture project [{$name}] does not exist.");
    }

    $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'envlens-'.str_replace('.', '', uniqid('', true));
    $files = new Filesystem;
    $files->copyDirectory($source, $target);

    $real = realpath($target);
    if ($real === false) {
        throw new RuntimeException("Could not create fixture directory [{$target}].");
    }

    $GLOBALS['envLensTempDirs'][] = $real;

    return $real;
}

function analysisIndex(): UsageIndex
{
    $scan = [
        'config_paths' => ['config'],
        'code_paths' => ['app', 'routes', 'database', 'resources/views', 'bootstrap'],
        'exclude' => ['vendor', 'storage', 'node_modules', 'bootstrap/cache'],
    ];

    return (new UsageScanner(new ConfigScanner, new CodeScanner, $scan))->scan(fixtureProject('basic'), false);
}

/**
 * @param  list<string>  $allow
 * @param  list<string>  $required  envlens.required
 */
function analyzer(array $allow = [], array $required = []): EnvAnalyzer
{
    return new EnvAnalyzer(
        new SensitivityDetector(['APP_KEY'], ['*PASSWORD*', '*SECRET*'], []),
        ['VITE_*'],
        $allow,
        $required,
    );
}

function statusOf(object $report, string $key): ?KeyStatus
{
    foreach ($report->rows as $row) {
        if ($row->key === $key) {
            return $row->status;
        }
    }

    return null;
}

/** @return list<Finding> */
function findingsOf(AuditReport $r, FindingCode $code): array
{
    return array_values(array_filter($r->findings, fn (Finding $f) => $f->code === $code));
}

afterEach(function (): void {
    $files = new Filesystem;

    foreach ($GLOBALS['envLensTempDirs'] as $dir) {
        $files->deleteDirectory($dir);
    }

    $GLOBALS['envLensTempDirs'] = [];
});

/** False on Windows, which has no POSIX file modes (only a read-only flag) and no read-only directories. */
function posixPermissions(): bool
{
    return PHP_OS_FAMILY !== 'Windows';
}
