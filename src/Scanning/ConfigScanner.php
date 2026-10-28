<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use SplObjectStorage;

final class ConfigScanner
{
    /**
     * @return array{usages: list<EnvUsage>, warnings: list<string>, files: int} files: PHP files found
     */
    public function scan(string $basePath, string $configDir, UsageSource $source = UsageSource::Config, ?string $package = null): array
    {
        $usages = [];
        $warnings = [];

        $realConfigDir = is_dir($configDir) ? realpath($configDir) : false;
        if ($realConfigDir === false) {
            return ['usages' => $usages, 'warnings' => $warnings, 'files' => 0];
        }
        $configDir = $realConfigDir;
        $basePath = realpath($basePath) ?: $basePath;

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;

        $files = $this->phpFiles($configDir);

        foreach ($files as $path) {
            $relative = $this->relative($basePath, $path);
            $code = @file_get_contents($path);
            if ($code === false) {
                $warnings[] = "Could not read {$relative}";

                continue;
            }

            try {
                $stmts = $parser->parse($code) ?? [];
            } catch (Error $e) {
                $warnings[] = "Could not parse {$relative}: {$e->getMessage()}";

                continue;
            }

            $prefix = str_replace('/', '.', substr($this->relative($configDir, $path), 0, -4));

            /** @var SplObjectStorage<Node, true> $seen */
            $seen = new SplObjectStorage;
            $record = function (Node $subtree, ?string $configPath) use (&$usages, $finder, $seen, $relative, $source, $package): void {
                foreach ($finder->find($subtree, fn (Node $n) => ($n instanceof FuncCall || $n instanceof StaticCall) && ! isset($seen[$n])) as $call) {
                    /** @var FuncCall|StaticCall $call */
                    $info = EnvCallExtractor::fromCall($call);
                    if ($info === null) {
                        continue;
                    }
                    $seen[$call] = true;
                    $usages[] = new EnvUsage(
                        $info['key'], $relative, $call->getStartLine(), $source, $configPath,
                        $info['default'], $info['defaultValue'], $info['pattern'], $package,
                    );
                }
            };

            foreach ($stmts as $stmt) {
                if ($stmt instanceof Return_ && $stmt->expr instanceof Array_) {
                    $this->walkArray($stmt->expr, $prefix, $record);
                    break;
                }
            }

            // Everything not attributed above has no config path.
            $record(new Node\Stmt\Block($stmts), null);
        }

        return ['usages' => $usages, 'warnings' => $warnings, 'files' => count($files)];
    }

    /**
     * @param  callable(Node, ?string): void  $record
     */
    private function walkArray(Array_ $array, ?string $path, callable $record): void
    {
        $nextIndex = 0;

        foreach ($array->items as $item) {
            $itemPath = null;
            if (! $item->unpack) {
                if ($item->key === null) {
                    $segment = (string) $nextIndex++;
                } elseif ($item->key instanceof String_ || $item->key instanceof Int_) {
                    $segment = (string) $item->key->value;
                    if ($item->key instanceof Int_ && $item->key->value >= $nextIndex) {
                        $nextIndex = $item->key->value + 1;
                    }
                } else {
                    $segment = null;
                }
                $itemPath = $path === null || $segment === null ? null : $path.'.'.$segment;
            }

            if ($item->value instanceof Array_) {
                if ($item->key !== null) {
                    $record($item->key, $itemPath);
                }
                $this->walkArray($item->value, $itemPath, $record);
            } else {
                $record($item, $itemPath);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getRealPath() ?: $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $base, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
