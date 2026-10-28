<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Touhidurabir\EnvLens\Analysis\ComparisonResult;
use Touhidurabir\EnvLens\Env\EnvFile;

final class CompareCommand extends EnvLensCommand
{
    private const ONLY = ['source', 'target', 'different', 'empty', 'missing'];

    protected $signature = 'env:compare
        {source? : Source env file (default: envlens.files.source)}
        {target? : Target env file (default: envlens.files.example)}
        {--all : Also list keys with the same value in both files}
        {--only= : Show only one kind of difference: source, target, different, empty or missing}
        {--reveal : Show values of non-sensitive keys}
        {--strict : Exit 1 when keys are missing from the target}
        {--fail-on-empty : Exit 1 when keys are empty in the target}
        {--vendor : Also scan the config of every vendor package and report framework usage in full}
        {--json : Output JSON}
        {--path= : Project base path}';

    protected $description = 'Compare two env files';

    protected function perform(): int
    {
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, self::ONLY, true)) {
            $this->error('Invalid --only value "'.(is_string($only) ? $only : '').'". Use one of: '.implode(', ', self::ONLY).'.');

            return 2;
        }

        $reveal = (bool) $this->option('reveal');

        if ($reveal && $this->refuseInProduction('reveal values')) {
            return 1;
        }

        $sourceName = $this->stringArgument('source', $this->sourceName());
        $targetName = $this->stringArgument('target', $this->exampleName());

        $source = $this->envFile($sourceName);
        $target = $this->envFile($targetName);

        if (realpath((string) $source->path()) === realpath((string) $target->path())) {
            $this->error('Source and target are the same file.');

            return 2;
        }

        $result = $this->lens()->analyzer()->compare($source, $target, $this->index(), $this->declaringExample($source, $target));

        $exit = (($this->option('strict') && $result->onlyInSource !== [])
            || ($this->option('fail-on-empty') && $result->emptyInTarget !== [])) ? 1 : 0;

        if ($this->wantsJson()) {
            return $this->json('env:compare', $result, $exit);
        }

        if ($result->inSync() && ! $this->option('all')) {
            $this->info("{$sourceName} and {$targetName} are in sync.");

            return $exit;
        }

        $kinds = $this->kinds($result, $sourceName, $targetName, $this->isExample($target));
        if ($only !== null) {
            $kinds = array_intersect_key($kinds, [$only => true]);
        }

        $this->newLine();
        $this->line("  Comparing <fg=cyan>{$sourceName}</> → <fg=cyan>{$targetName}</>");

        $rows = [];
        foreach ($kinds as $kind) {
            foreach ($kind['keys'] as $key) {
                $rows[] = [
                    $key,
                    $this->cell($key, $source, $reveal),
                    $this->cell($key, $target, $reveal),
                    $this->centered($this->isSensitive($key, $source, $target) ? '<fg=yellow>✔</>' : '<fg=gray>–</>'),
                    "<fg={$kind['color']}>{$kind['label']}</>",
                ];
            }
        }

        if ($rows === []) {
            $this->newLine();
            $this->info('No keys to list.');
        } else {
            $this->legend($kinds);
            $this->table(['Key', $sourceName, $targetName, 'Sensitive', 'Difference'], $rows);
        }

        $this->summary($result, $sourceName, $targetName);

        if ($result->inSync()) {
            $this->newLine();
            $this->info("{$sourceName} and {$targetName} are in sync.");
        }

        return $exit;
    }

    /**
     * The kinds of difference in display order, keyed by their --only name. Against an example
     * file, empty and differing values are expected (blank secrets, placeholders), so they are muted.
     *
     * @return array<string, array{keys: list<string>, label: string, color: string, meaning: string}>
     */
    private function kinds(ComparisonResult $result, string $sourceName, string $targetName, bool $example): array
    {
        $kinds = [
            'source' => ['keys' => $result->onlyInSource, 'label' => "only in {$sourceName}", 'color' => 'yellow', 'meaning' => "defined in {$sourceName}, not in {$targetName}"],
            'target' => ['keys' => $result->onlyInTarget, 'label' => "only in {$targetName}", 'color' => 'yellow', 'meaning' => "defined in {$targetName}, not in {$sourceName}"],
            'different' => $example
                ? ['keys' => $result->different, 'label' => 'different', 'color' => 'gray', 'meaning' => 'different values (placeholders are expected)']
                : ['keys' => $result->different, 'label' => 'different', 'color' => 'cyan', 'meaning' => 'set in both, with different values'],
            'empty' => $example
                ? ['keys' => $result->emptyInTarget, 'label' => "empty in {$targetName}", 'color' => 'gray', 'meaning' => 'empty there (expected for secrets)']
                : ['keys' => $result->emptyInTarget, 'label' => "empty in {$targetName}", 'color' => 'red', 'meaning' => "set in {$sourceName}, empty in {$targetName}"],
            'missing' => ['keys' => $result->missingFromBoth, 'label' => 'missing from both', 'color' => 'red', 'meaning' => 'required by config, in neither file'],
        ];

        if ($this->option('all')) {
            $kinds['same'] = ['keys' => $result->same, 'label' => 'same', 'color' => 'green', 'meaning' => 'same value in both'];
        }

        return array_filter($kinds, static fn (array $kind): bool => $kind['keys'] !== []);
    }

    /**
     * @param  array<string, array{keys: list<string>, label: string, color: string, meaning: string}>  $kinds
     */
    private function legend(array $kinds): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Difference</>');

        foreach ($kinds as $kind) {
            $this->components->twoColumnDetail("<fg={$kind['color']}>{$kind['label']}</>", $kind['meaning']);
        }

        $this->newLine();
    }

    private function summary(ComparisonResult $result, string $sourceName, string $targetName): void
    {
        $counts = $result->summary();
        $red = static fn (int $n): string => $n > 0 ? "<fg=red>{$n}</>" : '0';

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Summary</>');
        $this->components->twoColumnDetail("Keys in {$sourceName} / {$targetName}", "{$counts['source_keys']} / {$counts['target_keys']}");
        $this->components->twoColumnDetail('Same', (string) $counts['same']);
        $this->components->twoColumnDetail("Different / only in {$sourceName} / only in {$targetName}", "{$counts['different']} / {$counts['only_in_source']} / {$counts['only_in_target']}");
        $this->components->twoColumnDetail("Empty in {$targetName}", (string) $counts['empty_in_target']);
        $this->components->twoColumnDetail('Required, missing from both', $red($counts['missing_from_both']));
    }

    /** ✔ has a value, ✘ empty, – not in the file; with --reveal the (masked when sensitive) value. */
    private function cell(string $key, EnvFile $file, bool $reveal): TableCell|string
    {
        if (! $file->has($key)) {
            return $this->centered('<fg=gray>–</>');
        }

        if ($file->isEmpty($key)) {
            return $reveal ? '<fg=gray>(empty)</>' : $this->centered('<fg=red>✘</>');
        }

        if (! $reveal) {
            return $this->centered('<fg=green>✔</>');
        }

        $sensitivity = $this->lens()->sensitivity();
        $value = (string) $file->get($key);

        return $sensitivity->isSensitive($key, $value) ? $sensitivity->mask($value) : $value;
    }

    private function isSensitive(string $key, EnvFile $source, EnvFile $target): bool
    {
        $sensitivity = $this->lens()->sensitivity();

        return $sensitivity->isSensitive($key, $source->get($key)) || $sensitivity->isSensitive($key, $target->get($key));
    }

    private function centered(string $content): TableCell
    {
        return new TableCell($content, ['style' => new TableCellStyle(['align' => 'center'])]);
    }

    private function isExample(EnvFile $file): bool
    {
        return basename((string) $file->path()) === basename($this->exampleName());
    }

    /** The target or source when it is the example file, else the project's example file. */
    private function declaringExample(EnvFile $source, EnvFile $target): ?EnvFile
    {
        $example = basename($this->exampleName());

        foreach ([$target, $source] as $file) {
            if (basename((string) $file->path()) === $example) {
                return $file;
            }
        }

        return $this->exampleFile();
    }
}
