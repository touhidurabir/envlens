<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Touhidurabir\EnvLens\Analysis\KeyRow;
use Touhidurabir\EnvLens\Analysis\KeyStatus;
use Touhidurabir\EnvLens\Analysis\KeySummary;

final class KeysCommand extends EnvLensCommand
{
    private const ONLY = ['vendor', 'missing', 'unused', 'sensitive'];

    /** Legend text per status, printed above the table for the statuses it shows. */
    private const LEGEND = [
        'used' => 'read by config or code',
        'unused' => 'in the file, nothing reads it',
        'external' => 'in the file, read by tooling (cleanup.keep)',
        'missing' => 'not in the file, and required',
        'missing-with-default' => 'not in the file; config has a default',
        'missing-optional' => 'not in the file; no default, not required',
    ];

    protected $signature = 'env:keys
        {--file= : Env file to inspect (default: envlens.files.source)}
        {--missing : Also list keys app config or code reads that the file does not define}
        {--vendor : Also list vendor keys the file does not define (scans every vendor package)}
        {--only= : Show only one kind of key: vendor, missing, unused or sensitive}
        {--reveal : Show values of non-sensitive keys}
        {--json : Output JSON}
        {--path= : Project base path}';

    protected $description = 'List env keys with their usage and status';

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

        $withMissing = (bool) $this->option('missing') || $only === 'missing';
        $withVendor = (bool) $this->option('vendor') || $only === 'vendor';

        $file = $this->envFile($this->stringOption('file', $this->sourceName()));
        $report = $this->lens()->analyzer()->keys($file, $this->index(), $reveal, $this->exampleFile());

        $rows = array_values(array_filter(
            $report->rows,
            fn (KeyRow $row): bool => $this->listed($row, $withMissing, $withVendor) && $this->matchesOnly($row, $only),
        ));

        if ($this->wantsJson()) {
            return $this->json('env:keys', ['rows' => $rows, 'summary' => KeySummary::of($rows)]);
        }

        if ($rows === []) {
            $this->info('No keys to list.');
        } else {
            $this->legend($rows);
            $this->table(['Key', 'Value', 'Sensitive', 'Used by Config', 'Used In', 'Status'], $this->tableRows($rows, $reveal));
            $this->summary(KeySummary::of($rows));
        }

        if (! $withMissing && $only === null) {
            $this->hiddenHint($report->rows, $file->path() !== null ? basename($file->path()) : '.env');
        }

        return 0;
    }

    /** --only=vendor implies --vendor: the vendor scan must run for vendor keys to exist. */
    protected function includesVendor(): bool
    {
        return parent::includesVendor() || $this->option('only') === 'vendor';
    }

    /**
     * Keys in the file are always listed; keys the file lacks only when asked for:
     * app config/code keys with --missing, vendor-only keys with --vendor.
     */
    private function listed(KeyRow $row, bool $withMissing, bool $withVendor): bool
    {
        if ($row->inFile()) {
            return true;
        }

        return $row->vendorOnly() ? $withVendor : $withMissing;
    }

    private function matchesOnly(KeyRow $row, ?string $only): bool
    {
        return match ($only) {
            'vendor' => $row->vendorOnly(),
            'missing' => ! $row->inFile() && ! $row->vendorOnly(),
            'unused' => $row->status === KeyStatus::Unused,
            'sensitive' => $row->sensitive,
            default => true,
        };
    }

    /**
     * Tell the user about app keys the default view leaves out, naming the required ones.
     *
     * @param  list<KeyRow>  $all
     */
    private function hiddenHint(array $all, string $label): void
    {
        $hidden = array_filter($all, static fn (KeyRow $row): bool => ! $row->inFile() && ! $row->vendorOnly());

        if ($hidden === []) {
            return;
        }

        $required = array_map(
            static fn (KeyRow $row): string => $row->key,
            array_values(array_filter($hidden, static fn (KeyRow $row): bool => $row->status === KeyStatus::Missing)),
        );
        $optional = count($hidden) - count($required);

        $parts = [];
        if ($required !== []) {
            $parts[] = count($required).' required '.(count($required) === 1 ? 'key' : 'keys')." missing from {$label} (".implode(', ', $required).')';
        }
        if ($optional > 0) {
            $parts[] = $optional.' optional '.($optional === 1 ? 'key' : 'keys').' read by app config or code';
        }

        $this->newLine();
        $message = 'Not listed: '.implode(', ', $parts).'. Use --missing to list them.';
        $required !== [] ? $this->warn($message) : $this->line($message);
    }

    /**
     * The statuses the table shows, with what each means.
     *
     * @param  list<KeyRow>  $rows
     */
    private function legend(array $rows): void
    {
        $shown = array_unique(array_map(static fn (KeyRow $row): string => $row->status->value, $rows));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Status</>');

        foreach (self::LEGEND as $status => $meaning) {
            if (in_array($status, $shown, true)) {
                $this->components->twoColumnDetail($this->status($status), $meaning);
            }
        }

        $this->newLine();
    }

    private function summary(KeySummary $summary): void
    {
        $need = $summary->needValue > 0 ? "<fg=red>{$summary->needValue}</>" : '0';
        if ($summary->required > 0) {
            $need .= " ({$summary->required} required)";
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Summary</>');
        $this->components->twoColumnDetail('Total keys', (string) $summary->total);
        $this->components->twoColumnDetail('With a value', (string) $summary->withValue);
        $this->components->twoColumnDetail('Need a value', $need);
        $this->components->twoColumnDetail('Sensitive / not sensitive', $summary->sensitive.' / '.($summary->total - $summary->sensitive));
    }

    /**
     * One table row per key; a key read by several config paths spans several lines,
     * so keys are divided by separators to keep each group readable.
     *
     * @param  list<KeyRow>  $rows
     * @return list<list<string|TableCell>|TableSeparator>
     */
    private function tableRows(array $rows, bool $reveal): array
    {
        $table = [];

        foreach ($rows as $i => $row) {
            if ($i > 0) {
                $table[] = new TableSeparator;
            }

            $table[] = [
                $row->key,
                $reveal && $row->inFile() ? ($row->value ?? '') : $this->centered($row->hasValue ? '<fg=green>✔</>' : '<fg=red>✘</>'),
                $this->centered($row->sensitive ? '<fg=yellow>✔</>' : '<fg=gray>–</>'),
                implode("\n", $row->configPaths),
                $this->usedIn($row),
                $this->status($row->status->value),
            ];
        }

        return $table;
    }

    private function centered(string $content): TableCell
    {
        return new TableCell($content, ['style' => new TableCellStyle(['align' => 'center'])]);
    }

    private function status(string $status): string
    {
        $color = match ($status) {
            'used' => 'green',
            'unused', 'external' => 'yellow',
            'missing' => 'red',
            default => 'gray',
        };

        return "<fg={$color}>{$status}</>";
    }

    /**
     * Who reads the key: `app` (config), `app (code)`, `app (config, code)`, `app (pattern)`
     * for keys only matched by a dynamic key, and `vendor`. Package names are left to
     * env:explain and the JSON output to keep the column narrow.
     */
    private function usedIn(KeyRow $row): string
    {
        $kinds = [];
        foreach (['app' => 'config', 'code' => 'code', 'dynamic' => 'pattern'] as $source => $label) {
            if (in_array($source, $row->sources, true)) {
                $kinds[] = $label;
            }
        }

        $parts = [];
        if ($kinds !== []) {
            $parts[] = $kinds === ['config'] ? 'app' : 'app ('.implode(', ', $kinds).')';
        }
        foreach ($row->sources as $source) {
            if (str_starts_with($source, 'vendor:')) {
                $parts[] = 'vendor';
                break;
            }
        }

        return implode(', ', $parts);
    }
}
