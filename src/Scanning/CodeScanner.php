<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

final class CodeScanner
{
    /**
     * @param  list<string>  $paths  directories or files, relative to $basePath or absolute
     * @param  list<string>  $exclude  relative path prefixes to skip
     * @return array{usages: list<EnvUsage>, warnings: list<string>}
     */
    public function scan(string $basePath, array $paths, array $exclude): array
    {
        $usages = [];
        $warnings = [];

        $base = realpath($basePath);
        if ($base === false) {
            return ['usages' => $usages, 'warnings' => $warnings];
        }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $exclude = array_map(fn (string $e) => trim(str_replace('\\', '/', $e), '/'), $exclude);

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $blade = new BladeScanner($parser);
        $seenFiles = [];

        foreach ($paths as $path) {
            $real = realpath(UsageScanner::isAbsolute($path) ? $path : $base.'/'.ltrim($path, '/\\'));
            if ($real === false) {
                continue;
            }

            foreach ($this->files($real) as $file) {
                $relative = $this->relative($base, $file);
                if (isset($seenFiles[$relative]) || $this->excluded($relative, $exclude)) {
                    continue;
                }
                $seenFiles[$relative] = true;

                $code = @file_get_contents($file);
                if ($code === false) {
                    $warnings[] = "Could not read {$relative}";

                    continue;
                }

                if (str_ends_with($relative, '.blade.php')) {
                    foreach ($blade->scan($code) as $info) {
                        $usages[] = new EnvUsage(
                            $info['key'], $relative, $info['line'], UsageSource::Code, null,
                            $info['default'], $info['defaultValue'], $info['pattern'],
                        );
                    }

                    continue;
                }

                try {
                    $stmts = $parser->parse($code) ?? [];
                } catch (Error $e) {
                    $warnings[] = "Could not parse {$relative}: {$e->getMessage()}";

                    continue;
                }

                EnvCallExtractor::connectParents($stmts);

                foreach ($finder->find($stmts, fn (Node $n) => $n instanceof FuncCall || $n instanceof StaticCall) as $call) {
                    /** @var FuncCall|StaticCall $call */
                    $info = EnvCallExtractor::fromCall($call);
                    if ($info === null) {
                        continue;
                    }
                    $usages[] = new EnvUsage(
                        $info['key'], $relative, $call->getStartLine(), UsageSource::Code, null,
                        $info['default'], $info['defaultValue'], $info['pattern'],
                    );
                }
            }
        }

        return ['usages' => $usages, 'warnings' => $warnings];
    }

    /**
     * @param  list<string>  $exclude
     */
    private function excluded(string $relative, array $exclude): bool
    {
        foreach ($exclude as $prefix) {
            if ($prefix !== '' && ($relative === $prefix || str_starts_with($relative, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? [str_replace('\\', '/', $path)] : [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $base, string $path): string
    {
        return str_starts_with($path, $base.'/') ? substr($path, strlen($base) + 1) : $path;
    }
}
