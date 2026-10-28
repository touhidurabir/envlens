<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Support\Glob;

use function Laravel\Prompts\multiselect;
use function Termwind\render;
use function Termwind\renderUsing;

final class CleanupCommand extends EnvLensCommand
{
    protected $signature = 'env:cleanup
        {source? : Env file whose keys are kept (default: envlens.files.source; ignored with --unused)}
        {target? : Env file to remove keys from (default: envlens.files.example)}
        {--unused : Remove keys that nothing in the code reads}
        {--file= : File to clean (overrides the target argument)}
        {--vendor : Also scan the config of every vendor package and report framework usage in full}
        {--dry-run : Show the keys without removing them}
        {--force : Apply changes (skips the dry-run fallback; required in production)}
        {--path= : Project base path}';

    protected $description = 'Remove extra or unused keys from an env file (keys the app reads are always kept)';

    protected function perform(): int
    {
        $unused = (bool) $this->option('unused');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $production = $this->lens()->guard()->isProduction($this->basePath());

        $targetName = $this->stringOption('file', $this->stringArgument('target', $this->exampleName()));
        $target = $this->envFile($targetName);
        $targetPath = (string) $target->path();
        $live = $this->isLiveEnv($targetPath);

        if ($production && ! $dryRun) {
            // The app's own env file is never written in production, --force or not.
            if ($live) {
                $this->error("Refusing to remove keys from {$targetName} in production. Remove them by hand, or clean up on a non-production machine.");

                return 1;
            }

            if (! $force) {
                $this->error('Refusing to remove keys without --force in production.');

                return 1;
            }
        }

        $sync = $this->lens()->synchronizer();
        $index = $this->index();
        $sourceName = null;

        if ($unused) {
            if (! $index->hasConfigUsages()) {
                $this->error('No config files were scanned; refusing to treat every key as unused.');

                return 2;
            }

            $remove = $sync->unusedKeys($target, $index);
            // Read keys are not news in --unused mode; show only the less obvious reasons.
            $kept = array_values(array_filter(
                $target->keys(),
                static fn (string $k): bool => in_array($sync->keepReason($k, $index), ['dynamic', 'keep'], true),
            ));
        } else {
            $sourceName = $this->stringArgument('source', $this->sourceName());
            $source = $this->envFile($sourceName);

            if (realpath($targetPath) !== false && realpath($targetPath) === realpath((string) $source->path())) {
                $this->error('Source and target are the same file.');

                return 2;
            }

            $extra = $sync->extraKeys($source, $target);
            $remove = array_values(array_filter($extra, static fn (string $k): bool => $sync->keepReason($k, $index) === null));
            $kept = array_values(array_diff($extra, $remove));
        }

        $interactive = $this->input->isInteractive();
        $preview = $dryRun || (! $interactive && ! $force);

        // Termwind writes to stdout until told otherwise; send it through this command's output.
        renderUsing($this->output);

        $this->header($targetName, $sourceName, count($remove), $dryRun, $preview);
        $this->dynamicWarning($index);

        if ($remove === []) {
            $this->line("  <fg=green>Nothing to clean up in {$targetName}.</>");
        } else {
            $this->table(['Key', 'Value', 'Why it can go'], array_map(fn (string $key): array => [
                $key,
                new TableCell($this->hasValue($target, $key) ? '<fg=yellow>✔</>' : '<fg=gray>✘</>', ['style' => new TableCellStyle(['align' => 'center'])]),
                $this->why($sourceName),
            ], $remove));
        }

        $this->kept($kept, $index, $sourceName, $targetName);

        if ($remove === []) {
            $this->newLine();

            return 0;
        }

        $this->summary($remove, $kept, $target);

        if ($live) {
            $lost = count(array_filter($remove, fn (string $k): bool => $this->hasValue($target, $k)));

            if ($lost > 0) {
                $this->newLine();
                $this->warn($lost === 1
                    ? "  1 key has a value in {$targetName}; it is lost on removal (a backup is kept)."
                    : "  {$lost} keys have values in {$targetName}; they are lost on removal (a backup is kept).");
            }
        }

        if ($preview) {
            $this->newLine();

            return 0;
        }

        $selected = $remove;

        if ($interactive) {
            $options = [];
            foreach ($remove as $key) {
                $options[$key] = $key.($this->hasValue($target, $key) ? '  (has a value)' : '');
            }

            /** @var list<string> $picked */
            $picked = array_values(array_map(strval(...), multiselect(
                "Remove which keys from {$targetName}?",
                options: $options,
                default: $remove,
            )));

            // Only ever remove keys that were offered.
            $selected = array_values(array_intersect($remove, $picked));

            if ($selected === []) {
                $this->line('No keys selected.');

                return 0;
            }

            $count = count($selected);

            if (! $this->confirm("Remove {$count} ".$this->plural($count, 'key')." from {$targetName}?", false)) {
                $this->line('Aborted.');

                return 1;
            }
        }

        $writer = $this->lens()->writer();
        $backup = $writer->write($targetPath, $writer->apply($target, $sync->planRemoval($selected)), $this->backupDir());
        $count = count($selected);

        render(<<<HTML
            <div class="mx-2 mt-1">
                <span class="px-1 bg-green text-black font-bold">DONE</span>
                <span class="ml-1">Removed {$count} {$this->plural($count, 'key')} from {$targetName}.</span>
            </div>
        HTML);

        if ($backup !== null) {
            $this->line('  Backup: '.$this->relativePath($backup));
        }

        if (! $live) {
            $this->line("  <fg=gray>Review it before committing:</> git diff {$targetName}");
        }

        $this->newLine();

        return 0;
    }

    private function header(string $targetName, ?string $sourceName, int $count, bool $dryRun, bool $preview): void
    {
        $parts = [$sourceName !== null ? "keys not in {$sourceName}" : 'keys nothing reads'];

        if ($count > 0) {
            $parts[] = "{$count} to remove";
        }
        if ($dryRun) {
            $parts[] = '<fg=yellow>dry run, nothing removed</>';
        } elseif ($preview && $count > 0) {
            $parts[] = '<fg=yellow>dry run: pass --force to remove</>';
        }

        $this->newLine();
        $this->line("  Cleaning <fg=cyan>{$targetName}</> · ".implode(' · ', $parts));
        $this->newLine();
    }

    /** A fully dynamic env() call could read any key, so the list needs a human check. */
    private function dynamicWarning(UsageIndex $index): void
    {
        $fullyDynamic = array_values(array_filter($index->dynamic(), static fn (EnvUsage $u): bool => $u->dynamicPattern === null));

        if ($fullyDynamic === []) {
            return;
        }

        $where = implode(', ', array_map(static fn (EnvUsage $u): string => "{$u->file}:{$u->line}", $fullyDynamic));

        render(<<<HTML
            <div class="mx-2 mb-1">
                <span class="px-1 bg-yellow text-black font-bold">WARN</span>
                <span class="ml-1">env() with a fully dynamic key at {$where}: any key could be read there, so review the list.</span>
            </div>
        HTML);
    }

    private function why(?string $sourceName): string
    {
        return $sourceName !== null ? "not in {$sourceName}; nothing in the app reads it" : 'nothing in the app reads it';
    }

    /**
     * Keys this mode would otherwise remove, with the reason they stay.
     *
     * @param  list<string>  $kept
     */
    private function kept(array $kept, UsageIndex $index, ?string $sourceName, string $targetName): void
    {
        if ($kept === []) {
            return;
        }

        $sync = $this->lens()->synchronizer();

        $this->newLine();
        $this->line('  <options=bold>Kept</>');

        foreach ($kept as $key) {
            $reason = match ($sync->keepReason($key, $index)) {
                'read' => 'read by '.($index->configPaths($key) !== [] ? implode(', ', array_slice($index->configPaths($key), 0, 2)) : 'code')
                    .($sourceName !== null ? "; missing from {$sourceName}" : ''),
                'dynamic' => 'matches '.$this->dynamicMatch($key, $index),
                default => 'envlens.cleanup.keep',
            };

            $this->components->twoColumnDetail($key, "<fg=gray>{$reason}</>");
        }

        $read = array_filter($kept, static fn (string $k): bool => $sync->keepReason($k, $index) === 'read');
        if ($sourceName !== null && $read !== []) {
            $this->line('  <fg=gray>Add '.(count($read) === 1 ? 'it' : 'them')." to {$sourceName} with</> php artisan env:sync {$targetName} {$sourceName}");
        }
    }

    private function dynamicMatch(string $key, UsageIndex $index): string
    {
        foreach ($index->dynamic() as $usage) {
            if ($usage->dynamicPattern !== null && Glob::matches($usage->dynamicPattern, $key)) {
                return "{$usage->dynamicPattern} at {$usage->file}:{$usage->line}";
            }
        }

        return 'a runtime-built key';
    }

    /**
     * @param  list<string>  $remove
     * @param  list<string>  $kept
     */
    private function summary(array $remove, array $kept, EnvFile $target): void
    {
        $lost = count(array_filter($remove, fn (string $k): bool => $this->hasValue($target, $k)));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Summary</>');
        $this->components->twoColumnDetail('To remove / kept', count($remove).' / '.count($kept));
        $this->components->twoColumnDetail('Values lost', $lost > 0 ? "<fg=yellow>{$lost}</>" : '0');
    }

    private function hasValue(EnvFile $file, string $key): bool
    {
        return $file->has($key) && ! $file->isEmpty($key);
    }

    private function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }
}
