<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Touhidurabir\EnvLens\Analysis\Explanation;
use Touhidurabir\EnvLens\Analysis\Finding;
use Touhidurabir\EnvLens\Analysis\KeyStatus;
use Touhidurabir\EnvLens\Analysis\Severity;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageSource;

use function Laravel\Prompts\search;
use function Termwind\render;
use function Termwind\renderUsing;

final class ExplainCommand extends EnvLensCommand
{
    protected $signature = 'env:explain
        {key? : The env key to explain}
        {--reveal : Show values of non-sensitive keys}
        {--vendor : Also scan the config of every vendor package and report framework usage in full}
        {--json : Output JSON}
        {--path= : Project base path}';

    protected $description = 'Explain where an env key is used and defined (use -v to list overridden framework usages)';

    protected function perform(): int
    {
        $reveal = (bool) $this->option('reveal');

        if ($reveal && $this->refuseInProduction('reveal values')) {
            return 1;
        }

        $index = $this->index();
        $files = $this->files();

        $key = $this->argument('key');
        $key = is_string($key) && $key !== '' ? $key : null;

        if ($key === null) {
            if (! $this->input->isInteractive()) {
                $this->error('Provide a key.');

                return 2;
            }

            $known = $index->keys();
            foreach ($files as $file) {
                $known = array_merge($known, $file->keys());
            }
            $known = array_values(array_unique($known));
            sort($known);

            $key = (string) search(
                'Which key do you want to explain?',
                static fn (string $value): array => array_values(array_filter(
                    $known,
                    static fn (string $k): bool => $value === '' || stripos($k, $value) !== false,
                )),
            );
        }

        $example = $files[$this->exampleName()] ?? null;
        $explanation = $this->lens()->analyzer()->explain($key, $files, $index, $example, $reveal);

        if ($this->wantsJson()) {
            return $this->json('env:explain', $explanation, $explanation->unknown() ? 1 : 0);
        }

        // Termwind writes to stdout until told otherwise; send it through this command's output.
        renderUsing($this->output);

        if ($explanation->unknown()) {
            $this->error("{$key} is not read by any config or code and not defined in any env file.");

            if ($explanation->suggestions !== []) {
                $this->line('Did you mean: '.implode(', ', $explanation->suggestions).'?');
            }

            return 1;
        }

        $label = (string) array_key_first($files);

        $this->headline($explanation, $label);
        $this->components->twoColumnDetail('The app gets', $this->resolution($explanation, $label));
        $this->readBy($explanation);
        $this->envFiles($explanation);
        $this->findings($explanation->findings);

        return 0;
    }

    private function headline(Explanation $explanation, string $label): void
    {
        $status = $explanation->status ?? KeyStatus::Unused;
        $color = match ($status) {
            KeyStatus::Used => 'green',
            KeyStatus::Unused, KeyStatus::External => 'yellow',
            KeyStatus::Missing => 'red',
            default => 'gray',
        };
        $key = htmlspecialchars($explanation->key);
        $sensitive = $explanation->sensitive ? '<span class="ml-1 px-1 bg-yellow text-black">sensitive</span>' : '';

        render(<<<HTML
            <div class="mx-2 mt-1">
                <span class="font-bold">{$key}</span>
                {$sensitive}
                <span class="ml-1 px-1 bg-{$color} text-black">{$status->value}</span>
            </div>
        HTML);

        $empty = ($explanation->presence[$label] ?? 'absent') === 'empty';
        $sentence = match ($status) {
            KeyStatus::Used => $empty ? "Read by config or code, but empty in {$label}." : "Read by config or code and set in {$label}.",
            KeyStatus::Unused => ($explanation->presence[$label] ?? 'absent') === 'absent'
                ? 'Only in '.implode(', ', array_keys(array_filter($explanation->presence, static fn (string $s): bool => $s !== 'absent'))).'; nothing reads it.'
                : "Defined in {$label}, but nothing reads it.",
            KeyStatus::External => "In {$label} and kept for tooling outside PHP (envlens.cleanup.keep).",
            KeyStatus::Missing => "Read by config with no default and declared required, but not set in {$label}.",
            KeyStatus::MissingWithDefault => "Not set in {$label}; config falls back to its default.",
            KeyStatus::MissingOptional => "Not set in {$label}; read without a default, but not declared required.",
        };

        $this->line("  <fg=gray>{$sentence}</>");
        $this->newLine();
    }

    private function resolution(Explanation $explanation, string $label): string
    {
        return match ($explanation->resolves) {
            'value' => ($explanation->values[$label] ?? '')." <fg=gray>(from {$label})</>",
            'empty' => "'' <fg=gray>(empty string; config defaults are not used)</>",
            'default' => 'its config default <fg=gray>(see below; null where there is none)</>',
            default => "null <fg=gray>(not set in {$label})</>",
        };
    }

    private function readBy(Explanation $explanation): void
    {
        $this->newLine();
        $this->line('  <options=bold>Read by</>');

        if ($explanation->usages === [] && $explanation->dynamic === []) {
            $this->line('  <fg=gray>nothing</>');
        }

        foreach ($explanation->usages as $usage) {
            $this->usage($usage);
        }

        foreach ($explanation->dynamic as $usage) {
            $this->components->twoColumnDetail(
                ($usage->dynamicPattern ?? 'a key').' (built at runtime)',
                "<fg=gray>{$usage->file}:{$usage->line}</>",
            );
        }

        $overridden = count($explanation->overridden);
        if ($overridden > 0 && ! $this->output->isVerbose()) {
            $this->line("  <fg=gray>+{$overridden} framework ".($overridden === 1 ? 'default' : 'defaults').' overridden by your config (-v to list)</>');
        } else {
            foreach ($explanation->overridden as $usage) {
                $this->usage($usage, true);
            }
        }
    }

    private function usage(EnvUsage $usage, bool $overridden = false): void
    {
        $default = match ($usage->default->value) {
            'none' => 'no default',
            'null' => 'default null',
            'literal' => 'default '.$usage->defaultValue,
            default => 'default expression',
        };
        $subject = $usage->configPath ?? ($usage->source === UsageSource::Code ? 'env() in code' : 'env()');
        $extra = $overridden ? ' · overridden' : '';

        $file = $usage->file;
        if ($usage->source === UsageSource::Vendor && $usage->package !== null && str_starts_with($file, "vendor/{$usage->package}/")) {
            $file = "[{$usage->package}] ".substr($file, strlen("vendor/{$usage->package}/"));
        }

        $this->components->twoColumnDetail($subject, "<fg=gray>{$file}:{$usage->line} · {$default}{$extra}</>");
    }

    private function envFiles(Explanation $explanation): void
    {
        $this->newLine();
        $this->line('  <options=bold>Env files</>');

        foreach ($explanation->presence as $label => $state) {
            $this->components->twoColumnDetail($label, match ($state) {
                'set' => '<fg=green>✔</> '.($explanation->values[$label] ?? ''),
                'empty' => '<fg=red>✘</> empty',
                default => '<fg=gray>– not in file</>',
            });
        }
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function findings(array $findings): void
    {
        foreach ($findings as $finding) {
            [$badge, $color] = match ($finding->severity()) {
                Severity::Error => ['ERROR', 'red'],
                Severity::Warning => ['WARN', 'yellow'],
                Severity::Info => ['INFO', 'gray'],
            };

            render(<<<HTML
                <div class="mx-2 mt-1">
                    <span class="px-1 bg-{$color} text-black font-bold">{$badge}</span>
                    <span class="ml-1 font-bold">{$finding->code->value}</span>
                </div>
            HTML);

            $this->line("  {$finding->message}");

            if ($finding->hint !== null) {
                $this->line("  <fg=cyan>Fix:</> {$finding->hint}");
            }
        }

        $this->newLine();
    }

    /**
     * @return array<string, EnvFile>
     */
    private function files(): array
    {
        $base = $this->basePath();
        $names = array_values(array_unique([$this->sourceName(), $this->exampleName()]));
        $others = [];

        foreach (glob($base.DIRECTORY_SEPARATOR.'.env.*') ?: [] as $path) {
            $name = basename($path);

            if (! is_file($path) || in_array($name, $names, true)
                || str_contains($name, '.backup-') || str_starts_with($name, '.env.encrypted')) {
                continue;
            }

            $others[] = $name;
        }

        sort($others);

        $files = [];
        foreach (array_merge($names, $others) as $name) {
            $files[$name] = $this->envFile($name, false);
        }

        return $files;
    }
}
