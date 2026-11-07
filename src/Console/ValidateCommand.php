<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Touhidurabir\EnvLens\Console\Concerns\WritesAnnotations;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\InvalidRulesFile;
use Touhidurabir\EnvLens\Support\CiEnvironment;
use Touhidurabir\EnvLens\Validation\EnvValues;
use Touhidurabir\EnvLens\Validation\RuleSet;
use Touhidurabir\EnvLens\Validation\ValidationFailure;
use Touhidurabir\EnvLens\Validation\ValidationReport;

use function Termwind\render;
use function Termwind\renderUsing;

final class ValidateCommand extends EnvLensCommand
{
    use WritesAnnotations;

    protected $signature = 'env:validate
        {--file= : Env file to validate (default: envlens.files.source)}
        {--from-env : Validate the process environment instead of a file}
        {--rules= : Rules file (default: env.rules.php)}
        {--environment= : Environment whose rules to add (default: APP_ENV in the values, then the running app)}
        {--strict : Also fail on checks inferred from config}
        {--json : Output JSON}
        {--path= : Project base path}';

    protected $description = 'Validate env values against rules, or against checks inferred from config';

    protected function perform(): int
    {
        if ($this->option('from-env') && $this->option('file') !== null) {
            $this->error('--from-env reads the process environment, so it cannot be combined with --file.');

            return 2;
        }

        try {
            return $this->validateValues();
        } catch (InvalidRulesFile $e) {
            $this->error($e->getMessage());

            return 2;
        }
    }

    private function validateValues(): int
    {
        $fromEnv = (bool) $this->option('from-env');
        $source = $fromEnv ? 'the process environment' : $this->stringOption('file', $this->sourceName());

        if ($fromEnv) {
            $values = EnvValues::fromProcess();
        } else {
            $file = $this->envFile($source);

            if ($file->syntaxErrors() !== []) {
                return $this->syntaxFailure($file, $source);
            }

            $values = EnvValues::fromFile($file);
        }

        $rulesName = $this->stringOption('rules', RuleSet::FILE);
        $rulesPath = $this->resolve($rulesName);
        $hasRulesFile = is_file($rulesPath);

        if (! $hasRulesFile && $this->option('rules') !== null) {
            throw InvalidRulesFile::notLoadable($rulesName, 'file not found');
        }

        $set = $hasRulesFile ? RuleSet::fromFile($rulesPath) : new RuleSet;
        $environment = $this->environmentFor($values);
        $rules = $set->rulesFor($environment);
        $written = count($rules);
        $inferred = [];

        if ($set->infer) {
            foreach ($this->lens()->inferrer()->infer($this->index(), $this->exampleFile())->rules as $rule) {
                if (! isset($rules[$rule->key])) {
                    $rules[$rule->key] = $rule->rule;
                    $inferred[] = $rule->key;
                }
            }
        }

        $report = $this->lens()->validator()->validate($values, $rules, $set->messages, $inferred);
        $strict = (bool) $this->option('strict');
        $exit = $report->hasRuleFailures() || ($strict && $report->hasInferredFailures()) ? 1 : 0;

        if ($this->wantsJson()) {
            return $this->json('env:validate', [
                'values_from' => $source,
                'rules_file' => $hasRulesFile ? $rulesName : null,
                'environment' => $environment,
                'strict' => $strict,
            ] + $report->jsonSerialize(), $exit);
        }

        // Termwind writes to stdout until told otherwise; send it through this command's output.
        renderUsing($this->output);

        $this->annotations($report, $strict);
        $this->header($source, $hasRulesFile ? $rulesName : null, $written, count($inferred), $environment);
        $this->verdict($report, $exit);
        $this->failures($report);
        $this->summary($report);
        $this->notes($report, $hasRulesFile, $inferred !== [], $strict);

        return $exit;
    }

    /**
     * The file's own APP_ENV, so validating .env.production on a laptop still applies production's rules.
     *
     * @param  array<string, mixed>  $values
     */
    private function environmentFor(array $values): string
    {
        $fromValues = $values['APP_ENV'] ?? null;

        return $this->stringOption('environment', is_string($fromValues) && $fromValues !== '' ? $fromValues : (string) $this->laravel->environment());
    }

    private function syntaxFailure(EnvFile $file, string $source): int
    {
        if ($this->wantsJson()) {
            return $this->json('env:validate', [
                'values_from' => $source,
                'syntax_errors' => array_map(static fn ($e): array => ['line' => $e->line, 'message' => $e->message], $file->syntaxErrors()),
            ], 1);
        }

        foreach ($file->syntaxErrors() as $error) {
            if (CiEnvironment::isGitHubActions()) {
                $this->annotate('error', 'env:validate', "Syntax error: {$error->message}", $source, $error->line);
            }

            $this->error("Syntax error in {$source} line {$error->line}: {$error->message}");
        }

        return 1;
    }

    private function annotations(ValidationReport $report, bool $strict): void
    {
        if (! CiEnvironment::isGitHubActions()) {
            return;
        }

        $failures = $report->failures;
        usort($failures, static fn (ValidationFailure $a, ValidationFailure $b): int => $a->source <=> $b->source);

        foreach (array_reverse($failures) as $failure) {
            $blocks = $failure->source === ValidationFailure::FROM_RULES || $strict;
            $detail = $failure->message !== null ? ": {$failure->message}" : '';

            $this->annotate($blocks ? 'error' : 'warning', 'env:validate', "{$failure->key} failed ".implode(', ', $failure->rules).$detail);
        }
    }

    private function header(string $source, ?string $rulesName, int $written, int $inferred, string $environment): void
    {
        $parts = [];

        if ($rulesName !== null) {
            $parts[] = "{$written} ".$this->plural($written, 'rule')." from {$rulesName}";
        }

        if ($inferred > 0) {
            $parts[] = "{$inferred} inferred";
        }

        $parts[] = "environment: {$environment}";

        $this->newLine();
        $this->line("  Validating <fg=cyan>{$source}</> · ".implode(' · ', $parts));
    }

    private function verdict(ValidationReport $report, int $exit): void
    {
        [$badge, $color] = match (true) {
            $exit === 1 => ['FAIL', 'red'],
            $report->failures !== [] => ['WARN', 'yellow'],
            default => ['PASS', 'green'],
        };

        $failed = count($report->failures);
        $summary = $report->checked === 0
            ? 'Nothing to check'
            : "{$failed} of {$report->checked} ".$this->plural($report->checked, 'check').' failed';

        render(<<<HTML
            <div class="mx-2 my-1">
                <span class="px-1 bg-{$color} text-black font-bold">{$badge}</span>
                <span class="ml-1">{$summary}</span>
                <span class="ml-1 text-gray">(exit {$exit})</span>
            </div>
        HTML);
    }

    private function failures(ValidationReport $report): void
    {
        if ($report->failures === []) {
            return;
        }

        $failures = $report->failures;
        usort($failures, static fn (ValidationFailure $a, ValidationFailure $b): int => [$b->source, $a->key] <=> [$a->source, $b->key]);

        $this->table(['Key', 'Sensitive', 'Source', 'Failed rule', 'Message'], array_map(static fn (ValidationFailure $f): array => [
            $f->key,
            $f->sensitive ? '✔' : '–',
            $f->source,
            implode(', ', $f->rules),
            $f->message ?? '(hidden: sensitive key)',
        ], $failures));
    }

    private function summary(ValidationReport $report): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Summary</>');
        $this->components->twoColumnDetail('Checked', (string) $report->checked);
        $this->components->twoColumnDetail('Failed: rules / inferred', $report->count(ValidationFailure::FROM_RULES).' / '.$report->count(ValidationFailure::INFERRED));
    }

    private function notes(ValidationReport $report, bool $hasRulesFile, bool $usedInference, bool $strict): void
    {
        $this->newLine();

        if ($usedInference) {
            if ($report->hasInferredFailures() && ! $strict) {
                $this->line('  <fg=gray>Inferred checks are guesses from config defaults, so they warn. Use --strict to fail on them.</>');
            }

            if (! $hasRulesFile) {
                $this->line('  <fg=gray>No '.RuleSet::FILE.': these checks were inferred from your config. Review and keep them:</> php artisan env:validate --init');
            }

            $this->newLine();
        }
    }

    private function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }
}
