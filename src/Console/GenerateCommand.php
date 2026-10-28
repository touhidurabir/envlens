<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Touhidurabir\EnvLens\Console\Concerns\RendersChanges;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Mutation\GeneratedEnv;
use Touhidurabir\EnvLens\Scanning\UsageIndex;

use function Termwind\render;
use function Termwind\renderUsing;

final class GenerateCommand extends EnvLensCommand
{
    use RendersChanges;

    protected $signature = 'env:generate
        {target? : File to generate (default: envlens.files.example)}
        {--source= : Env file to read keys from (default: envlens.files.source)}
        {--with-values : Copy non-sensitive values from the source}
        {--no-defaults : Do not fill in literal defaults from config}
        {--dry-run : Show what would be written without writing}
        {--force : Overwrite an existing target without asking}
        {--path= : Project base path}';

    protected $description = 'Generate an example env file from a real one';

    protected function perform(): int
    {
        $withValues = (bool) $this->option('with-values');
        $noDefaults = (bool) $this->option('no-defaults');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $production = $this->lens()->guard()->isProduction($this->basePath());

        if ($production && $withValues) {
            $this->error('Refusing to copy values in production.');

            return 1;
        }

        $targetName = $this->stringArgument('target', $this->exampleName());
        $sourceName = $this->stringOption('source', $this->sourceName());

        $source = $this->envFile($sourceName);
        $target = $this->envFile($targetName, false);
        $targetPath = (string) $target->path();
        $exists = is_file($targetPath);
        $live = $this->isLiveEnv($targetPath);

        if (realpath($targetPath) !== false && realpath($targetPath) === realpath((string) $source->path())) {
            $this->error('Source and target are the same file.');

            return 2;
        }

        if ($live && ! $dryRun) {
            // A generated file has no real values: writing it over the app's env file destroys every secret.
            if ($exists) {
                $this->error("Refusing to overwrite {$targetName}: a generated file has no real values. Use env:sync {$sourceName} {$targetName} to add missing keys.");

                return 1;
            }

            if ($production) {
                $this->error("Refusing to write {$targetName} in production.");

                return 1;
            }
        }

        if ($production && ! $dryRun && ! $force) {
            $this->error('Refusing to generate without --force in production.');

            return 1;
        }

        $index = $this->index();
        $result = $this->lens()->generator()->generate($source, $index, $withValues, ! $noDefaults);

        // Termwind writes to stdout until told otherwise; send it through this command's output.
        renderUsing($this->output);

        $this->header($sourceName, $targetName, $result->keys, $exists, $dryRun);
        $this->changesTable($result->changes);
        $this->summary($result);

        if ($exists) {
            $this->overwriteWarning($target, $result, $targetName, $index);
        }

        if ($dryRun) {
            $this->newLine();

            return 0;
        }

        if ($exists && ! $force) {
            if (! $this->input->isInteractive()) {
                $this->error("{$targetName} exists. Use --force to overwrite.");

                return 1;
            }

            if (! $this->confirm("{$targetName} exists. Overwrite?", false)) {
                $this->line('Tip: use env:sync to add only missing keys.');

                return 1;
            }
        }

        $backup = $this->lens()->writer()->write($targetPath, $result->file, $this->backupDir());

        render(<<<HTML
            <div class="mx-2 mt-1">
                <span class="px-1 bg-green text-black font-bold">DONE</span>
                <span class="ml-1">Generated {$targetName} ({$result->keys} {$this->plural($result->keys, 'key')}).</span>
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

    private function header(string $sourceName, string $targetName, int $keys, bool $exists, bool $dryRun): void
    {
        $parts = ["{$keys} ".$this->plural($keys, 'key'), ($exists ? 'overwrites ' : 'creates ').$targetName];

        if ($dryRun) {
            $parts[] = '<fg=yellow>dry run, nothing written</>';
        }

        $this->newLine();
        $this->line("  Generating <fg=cyan>{$targetName}</> from <fg=cyan>{$sourceName}</> · ".implode(' · ', $parts));
        $this->newLine();
    }

    private function summary(GeneratedEnv $result): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Summary</>');
        $this->components->twoColumnDetail('Keys written', (string) $result->keys);
        $this->valueCounts($result->changes);
        $this->components->twoColumnDetail('Commented-out values blanked', (string) $result->blankedComments);
        $this->components->twoColumnDetail(
            'Unparseable lines dropped',
            $result->droppedAt === [] ? '0' : '<fg=yellow>'.count($result->droppedAt).'</> ('.$this->plural(count($result->droppedAt), 'line').' '.implode(', ', $result->droppedAt).')',
        );
    }

    /** What overwriting throws away: keys only in the current file, and values the team set there. */
    private function overwriteWarning(EnvFile $target, GeneratedEnv $result, string $targetName, UsageIndex $index): void
    {
        $new = [];
        foreach ($result->changes as $change) {
            $new[$change->key] = $change;
        }

        $lost = array_values(array_filter($target->keys(), static fn (string $k): bool => ! isset($new[$k])));
        $replaced = count(array_filter(
            $target->keys(),
            static fn (string $k): bool => isset($new[$k]) && ! $target->isEmpty($k) && $target->get($k) !== $new[$k]->value,
        ));

        if ($lost === [] && $replaced === 0) {
            return;
        }

        $parts = [];
        if ($lost !== []) {
            $names = array_map(static fn (string $k): string => $index->isUsed($k) ? "{$k} <fg=red>(read by the app)</>" : $k, $lost);
            $parts[] = count($lost).' '.$this->plural(count($lost), 'key').' only in it ('.implode(', ', $names).')';
        }
        if ($replaced > 0) {
            $parts[] = "{$replaced} ".$this->plural($replaced, 'value').' set there';
        }

        $this->newLine();
        render(<<<HTML
            <div class="mx-2">
                <span class="px-1 bg-yellow text-black font-bold">WARN</span>
                <span class="ml-1">Overwriting {$targetName} loses:</span>
            </div>
        HTML);
        $this->line('  '.implode('; ', $parts).'.');
        $this->line('  <fg=gray>To add only missing keys instead:</> php artisan env:sync');
    }

    private function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }
}
