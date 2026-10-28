<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Touhidurabir\EnvLens\Console\Concerns\RendersChanges;
use Touhidurabir\EnvLens\Mutation\Plan;
use Touhidurabir\EnvLens\Scanning\UsageIndex;

use function Laravel\Prompts\multiselect;
use function Termwind\render;
use function Termwind\renderUsing;

final class SyncCommand extends EnvLensCommand
{
    use RendersChanges;

    protected $signature = 'env:sync
        {source? : Env file to read keys from (default: envlens.files.source)}
        {target? : Env file to add missing keys to (default: envlens.files.example)}
        {--with-values : Copy non-sensitive values from the source}
        {--no-defaults : Do not fill in literal defaults from config}
        {--dry-run : Show the plan without writing}
        {--force : Write in a production environment}
        {--path= : Project base path}';

    protected $description = 'Add keys missing from the target env file';

    protected function perform(): int
    {
        $withValues = (bool) $this->option('with-values');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $production = $this->lens()->guard()->isProduction($this->basePath());

        $sourceName = $this->stringArgument('source', $this->sourceName());
        $targetName = $this->stringArgument('target', $this->exampleName());

        $source = $this->envFile($sourceName);
        $target = $this->envFile($targetName, false);
        $targetPath = (string) $target->path();

        if (realpath($targetPath) !== false && realpath($targetPath) === realpath((string) $source->path())) {
            $this->error('Source and target are the same file.');

            return 2;
        }

        $live = $this->isLiveEnv($targetPath);

        if ($production) {
            // The app's own env file is never written in production, --force or not.
            if ($live && ! $dryRun) {
                $this->error("Refusing to write to {$targetName} in production. Add the keys by hand, or sync on a non-production machine.");

                return 1;
            }

            if ($withValues) {
                $this->error('Refusing to copy values in production.');

                return 1;
            }

            if (! $dryRun && ! $force) {
                $this->error('Refusing to write without --force in production.');

                return 1;
            }
        }

        $sync = $this->lens()->synchronizer();
        $index = $this->index();
        $missing = $sync->missingKeys($source, $target);
        $creates = ! is_file($targetPath);

        if ($missing === []) {
            $this->info("{$targetName} already has every key in {$sourceName}; nothing to add.");
            $this->notTouched($sync->extraKeys($source, $target), $sourceName, $targetName, $index, $live);

            return 0;
        }

        $sensitivity = $this->lens()->sensitivity();
        $safe = array_values(array_filter($missing, static fn (string $k): bool => ! $sensitivity->isSensitive($k, $source->get($k))));
        $interactive = $this->input->isInteractive();
        $copy = [];

        if (! $production) {
            if ($interactive && ! $dryRun) {
                if ($safe !== []) {
                    $options = [];
                    foreach ($safe as $key) {
                        $value = (string) $source->get($key);
                        $options[$key] = $value === '' ? "{$key}  (empty)" : "{$key}  ".$this->shortenValue($value);
                    }

                    /** @var list<string> $copy */
                    $copy = array_values(array_map(strval(...), multiselect(
                        'Copy values for which keys? (secrets are never copied)',
                        options: $options,
                        default: [],
                    )));
                }
            } elseif ($withValues) {
                $copy = $safe;
            }
        }

        $plan = $sync->planSync($source, $target, $index, $copy, ! (bool) $this->option('no-defaults'));
        $count = count($plan->adds());

        // Termwind writes to stdout until told otherwise; send it through this command's output.
        renderUsing($this->output);

        $this->header($sourceName, $targetName, $count, $creates, $dryRun);
        $this->changesTable($plan->adds());
        $this->summary($plan);
        $this->notTouched($sync->extraKeys($source, $target), $sourceName, $targetName, $index, $live);

        if ($dryRun) {
            return 0;
        }

        if ($interactive && ! $this->confirm("Write {$count} ".$this->plural($count, 'key')." to {$targetName}?", true)) {
            $this->line('Aborted.');

            return 1;
        }

        $writer = $this->lens()->writer();
        $backup = $writer->write($targetPath, $writer->apply($target, $plan), $this->backupDir());

        render(<<<HTML
            <div class="mx-2 mt-1">
                <span class="px-1 bg-green text-black font-bold">DONE</span>
                <span class="ml-1">Added {$count} {$this->plural($count, 'key')} to {$targetName}.</span>
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

    private function header(string $sourceName, string $targetName, int $count, bool $creates, bool $dryRun): void
    {
        $parts = ["{$count} ".$this->plural($count, 'key').' to add'];
        if ($creates) {
            $parts[] = "creates {$targetName}";
        }
        if ($dryRun) {
            $parts[] = '<fg=yellow>dry run, nothing written</>';
        }

        $this->newLine();
        $this->line("  Syncing <fg=cyan>{$sourceName}</> → <fg=cyan>{$targetName}</> · ".implode(' · ', $parts));
        $this->newLine();
    }

    private function summary(Plan $plan): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Summary</>');
        $this->components->twoColumnDetail('To add', (string) count($plan->adds()));
        $this->valueCounts($plan->adds());
    }

    /**
     * Sync only adds; point at keys it leaves alone in the target. Keys the app reads belong in
     * the source (they are missing there); only the rest are cleanup candidates.
     *
     * @param  list<string>  $extra
     */
    private function notTouched(array $extra, string $sourceName, string $targetName, UsageIndex $index, bool $live): void
    {
        if ($extra === []) {
            return;
        }

        $read = array_values(array_filter($extra, static fn (string $k): bool => $index->isUsed($k) || $index->isProtectedByDynamic($k)));
        $unread = array_values(array_diff($extra, $read));

        $this->newLine();
        $n = count($extra);
        $this->line("  <fg=gray>Not touched: {$n} ".$this->plural($n, 'key')." only in {$targetName}.</>");

        if ($read !== []) {
            $this->line("  <fg=gray>-</> {$this->names($read)}: read by the app but missing from {$sourceName}; add ".(count($read) === 1 ? 'it' : 'them')." with php artisan env:sync {$targetName} {$sourceName}.");
        }
        if ($unread !== []) {
            // Never suggest deleting from the app's own env file: tools outside PHP may read those keys.
            $this->line("  <fg=gray>-</> {$this->names($unread)}: nothing in the app reads ".(count($unread) === 1 ? 'it' : 'them').'; '
                .($live ? 'check with php artisan env:explain KEY before removing anything by hand.' : "remove with php artisan env:cleanup {$sourceName} {$targetName}."));
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function names(array $names): string
    {
        $n = count($names);

        return implode(', ', array_slice($names, 0, 3)).($n > 3 ? ', +'.($n - 3).' more' : '');
    }

    private function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }
}
