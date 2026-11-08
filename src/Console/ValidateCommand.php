<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Touhidurabir\EnvLens\Console\Concerns\WritesAnnotations;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\InvalidRulesFile;
use Touhidurabir\EnvLens\Exceptions\UnparseableEnvFile;
use Touhidurabir\EnvLens\Support\CiEnvironment;
use Touhidurabir\EnvLens\Validation\CheckedRule;
use Touhidurabir\EnvLens\Validation\EnvValues;
use Touhidurabir\EnvLens\Validation\Inference;
use Touhidurabir\EnvLens\Validation\InferredRule;
use Touhidurabir\EnvLens\Validation\RulePlan;
use Touhidurabir\EnvLens\Validation\RuleSet;
use Touhidurabir\EnvLens\Validation\RulesFileWriter;
use Touhidurabir\EnvLens\Validation\ValidationFailure;
use Touhidurabir\EnvLens\Validation\ValidationReport;

use function Laravel\Prompts\select;
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
        {--init : Generate a rules file from your config, showing the assumption behind each rule}
        {--dry-run : With --init, show the rules without writing}
        {--show-rules-only : Show the rules that would run, and why, without checking any values}
        {--json : Output JSON}
        {--path= : Project base path}';

    protected $description = 'Validate env values against rules, or against checks inferred from config';

    protected function perform(): int
    {
        if ($this->option('from-env') && $this->option('file') !== null) {
            $this->error('--from-env reads the process environment, so it cannot be combined with --file.');

            return 2;
        }

        if ($this->option('dry-run') && ! $this->option('init')) {
            $this->error('--dry-run needs --init.');

            return 2;
        }

        if ($this->option('show-rules-only') && $this->option('init')) {
            $this->error('--show-rules-only cannot be combined with --init, which already shows what it would generate.');

            return 2;
        }

        if ($this->option('init')) {
            return $this->generateRules();
        }

        try {
            return $this->option('show-rules-only') ? $this->showRules() : $this->validateValues();
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

            try {
                $values = EnvValues::fromFile($file);
            } catch (UnparseableEnvFile $e) {
                return $this->parseFailure($e, $source);
            }
        }

        $rulesName = $this->stringOption('rules', RuleSet::FILE);
        $hasRulesFile = $this->hasRulesFile($rulesName);

        if (! $hasRulesFile && ! $this->index(false)->hasConfigUsages()) {
            return $this->nothingToInfer();
        }

        $asked = ! $hasRulesFile && $this->shouldPrompt();

        if ($asked && $this->choosesToGenerate($source)) {
            $generated = $this->generateRules();

            if ($generated !== 0) {
                return $generated;
            }

            $hasRulesFile = true;
        }

        $set = $hasRulesFile ? RuleSet::fromFile($this->resolve($rulesName)) : new RuleSet;
        $plan = $this->plan($set, $hasRulesFile ? $rulesName : null, $values);
        $report = $this->lens()->validator()->validate($values, $plan->rules, $set->messages, $plan->inferredKeys);
        $strict = (bool) $this->option('strict');
        $exit = $report->hasRuleFailures() || ($strict && $report->hasInferredFailures()) ? 1 : 0;

        if ($this->wantsJson()) {
            return $this->json('env:validate', [
                'values_from' => $source,
                'rules_file' => $hasRulesFile ? $rulesName : null,
                'environment' => $plan->environment,
                'strict' => $strict,
                'rules' => $plan->checks,
            ] + $report->jsonSerialize(), $exit);
        }

        // Termwind writes to stdout until told otherwise; send it through this command's output.
        renderUsing($this->output);

        $this->annotations($report, $strict);
        $this->newLine();
        $this->line("  Validating <fg=cyan>{$source}</> · ".$this->planSummary($plan, $hasRulesFile ? $rulesName : null));
        $this->checksTable($plan);
        $this->verdict($report, $exit);
        $this->failures($report);
        $this->summary($report);
        $this->notes($report, $hasRulesFile, $plan->inferredKeys !== [], $strict);

        if ($asked && ! $hasRulesFile) {
            $this->line('  <fg=gray>Use -n to skip this question next time.</>');
            $this->newLine();
        }

        return $exit;
    }

    /** Read-only: it runs no rule, asks nothing and writes nothing, so it needs no values and no .env. */
    private function showRules(): int
    {
        $rulesName = $this->stringOption('rules', RuleSet::FILE);
        $hasRulesFile = $this->hasRulesFile($rulesName);

        if (! $hasRulesFile && ! $this->index(false)->hasConfigUsages()) {
            return $this->nothingToInfer();
        }

        $set = $hasRulesFile ? RuleSet::fromFile($this->resolve($rulesName)) : new RuleSet;
        $plan = $this->plan($set, $hasRulesFile ? $rulesName : null, $this->valuesForEnvironment());

        $this->lens()->validator()->ensureUsable($plan->rules);

        if ($this->wantsJson()) {
            return $this->json('env:validate', [
                'rules_file' => $hasRulesFile ? $rulesName : null,
                'environment' => $plan->environment,
                'rules' => $plan->checks,
            ]);
        }

        $this->newLine();
        $this->line('  Rules <fg=cyan>env:validate</> would check · '.$this->planSummary($plan, $hasRulesFile ? $rulesName : null));
        $this->checksTable($plan);
        $this->newLine();
        $this->line($plan->checks === []
            ? '  <fg=gray>No rules would run.</>'
            : '  <fg=gray>No values were checked. To check them:</> php artisan env:validate');
        $this->newLine();

        return 0;
    }

    /** A named rules file has to exist. The default one is optional. */
    private function hasRulesFile(string $name): bool
    {
        $exists = is_file($this->resolve($name));

        if (! $exists && $this->option('rules') !== null) {
            throw InvalidRulesFile::notLoadable($name, 'file not found');
        }

        return $exists;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function plan(RuleSet $set, ?string $rulesName, array $values): RulePlan
    {
        return RulePlan::build(
            $set,
            $rulesName,
            $this->environmentFor($values),
            $set->infer ? $this->lens()->inferrer()->infer($this->index(), $this->exampleFile()) : null,
            $this->lens()->sensitivity(),
            $values,
        );
    }

    /**
     * Only for choosing the environment and spotting sensitive values, so an env file that is absent or
     * broken is no reason not to show the rules. One named with --file must exist, in case of a typo.
     *
     * @return array<string, mixed>
     */
    private function valuesForEnvironment(): array
    {
        if ($this->option('from-env')) {
            return EnvValues::fromProcess();
        }

        $named = $this->option('file') !== null;
        $file = $this->envFile($this->stringOption('file', $this->sourceName()), $named);

        try {
            return EnvValues::fromFile($file);
        } catch (UnparseableEnvFile) {
            return [];
        }
    }

    /** Only a person at a terminal can answer: not in CI, in production or with --json. EnvLensCommand handles no terminal. */
    private function shouldPrompt(): bool
    {
        return $this->input->isInteractive()
            && ! $this->wantsJson()
            && ! CiEnvironment::isCi()
            && ! $this->lens()->guard()->isProduction($this->basePath());
    }

    private function choosesToGenerate(string $source): bool
    {
        return select(
            label: 'No '.RuleSet::FILE." found. How do you want to validate {$source}?",
            options: [
                'infer' => 'Run checks inferred from your config (writes nothing)',
                'generate' => 'Generate '.RuleSet::FILE.' from your config and review it',
            ],
            default: 'infer',
        ) === 'generate';
    }

    private function generateRules(): int
    {
        $name = $this->stringOption('rules', RuleSet::FILE);
        $path = $this->resolve($name);
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && is_file($path)) {
            $this->error("{$name} already exists, and is never overwritten. To see what would be generated, use --init --dry-run.");

            return 1;
        }

        if (! $dryRun && $this->lens()->guard()->isProduction($this->basePath())) {
            $this->error("Refusing to write {$name} in production: it belongs in the repository.");

            return 1;
        }

        if (! $this->index(false)->hasConfigUsages()) {
            return $this->nothingToInfer();
        }

        $inference = $this->lens()->inferrer()->infer($this->index(), $this->exampleFile());

        if ($this->wantsJson()) {
            if (! $dryRun && @file_put_contents($path, (new RulesFileWriter)->render($inference)) === false) {
                $this->error("Could not write {$name}.");

                return 1;
            }

            return $this->json('env:validate', ['file' => $name, 'written' => ! $dryRun, 'rules' => $inference->rules, 'skipped' => $inference->skipped]);
        }

        renderUsing($this->output);

        $this->rulesTable($inference, $name, $dryRun);
        $this->rulesSummary($inference);

        if ($dryRun) {
            $this->newLine();

            return 0;
        }

        $this->newLine();

        if ($this->shouldPrompt() && ! $this->confirm("Write {$name}?", true)) {
            $this->line('Aborted.');

            return 1;
        }

        if (@file_put_contents($path, (new RulesFileWriter)->render($inference)) === false) {
            $this->error("Could not write {$name}.");

            return 1;
        }

        render(<<<HTML
            <div class="mx-2">
                <span class="px-1 bg-green text-black font-bold">DONE</span>
                <span class="ml-1">Generated {$name}.</span>
            </div>
        HTML);
        $this->line("  <fg=gray>Review it, then commit it:</> git add {$name}");
        $this->newLine();

        return 0;
    }

    /** A check that looked at nothing must not pass. */
    private function nothingToInfer(): int
    {
        $this->error('No env() reads were found in config and there is no rules file, so there is nothing to check. Set envlens.scan.config_paths if your config lives elsewhere.');

        return 2;
    }

    private function rulesTable(Inference $inference, string $name, bool $dryRun): void
    {
        $rules = $inference->rules;
        usort($rules, static fn (InferredRule $a, InferredRule $b): int => $a->key <=> $b->key);
        $count = count($rules);

        $this->newLine();
        $this->line("  Generating <fg=cyan>{$name}</> from your config · {$count} ".$this->plural($count, 'rule').($dryRun ? ' · <fg=yellow>dry run, nothing written</>' : ''));
        $this->newLine();

        $this->table(['Key', 'Rule', 'Assumption', 'Read at'], array_map(static fn (InferredRule $r): array => [
            $r->key,
            $r->rule.($r->active ? '' : ' (commented out)'),
            $r->assumption,
            $r->file !== null ? "{$r->file}:{$r->line}" : '',
        ], $rules));
    }

    private function rulesSummary(Inference $inference): void
    {
        $active = count(array_filter($inference->rules, static fn (InferredRule $r): bool => $r->active));
        $commented = count($inference->rules) - $active;
        $skipped = $inference->skipped;

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Summary</>');
        $this->components->twoColumnDetail('Active rules / commented out', "{$active} / {$commented}");
        $this->components->twoColumnDetail('No rule: free-text / non-literal / conflicting default', count($skipped['free']).' / '.count($skipped['nonLiteral']).' / '.count($skipped['conflicting']));
        $this->components->twoColumnDetail('No rule: no default and not declared', (string) count($skipped['none']));

        if ($this->output->isVerbose()) {
            foreach (['free' => 'Free-text default', 'nonLiteral' => 'Non-literal default', 'conflicting' => 'Conflicting defaults', 'none' => 'No default and not declared'] as $reason => $label) {
                if ($skipped[$reason] !== []) {
                    $this->newLine();
                    $this->line("  <options=bold>{$label}</>");
                    $this->line('  <fg=gray>'.implode(', ', $skipped[$reason]).'</>');
                }
            }
        }
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

    private function parseFailure(UnparseableEnvFile $e, string $source): int
    {
        if ($this->wantsJson()) {
            return $this->json('env:validate', ['values_from' => $source, 'syntax_errors' => [['line' => null, 'message' => $e->getMessage()]]], 1);
        }

        if (CiEnvironment::isGitHubActions()) {
            $this->annotate('error', 'env:validate', $e->getMessage(), $source);
        }

        $this->error("{$source}: {$e->getMessage()}");

        return 1;
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

    private function planSummary(RulePlan $plan, ?string $rulesName): string
    {
        $written = $plan->count(ValidationFailure::FROM_RULES);
        $inferred = $plan->count(ValidationFailure::INFERRED);
        $parts = [];

        if ($rulesName !== null) {
            $parts[] = "{$written} ".$this->plural($written, 'rule')." from {$rulesName}";
        }

        if ($inferred > 0) {
            $parts[] = "{$inferred} inferred";
        }

        $parts[] = "environment: {$plan->environment}";

        return implode(' · ', $parts);
    }

    private function checksTable(RulePlan $plan): void
    {
        if ($plan->checks === []) {
            return;
        }

        $this->newLine();
        $this->table(['Key', 'Rules', 'Source', 'Based on'], array_map(static fn (CheckedRule $c): array => [
            $c->key,
            implode('|', $c->rules),
            $c->source,
            $c->basedOn,
        ], $plan->checks));
    }

    private function verdict(ValidationReport $report, int $exit): void
    {
        [$badge, $color] = match (true) {
            $exit === 1 => ['FAIL', 'red'],
            $report->failures !== [] => ['WARN', 'yellow'],
            default => ['PASS', 'green'],
        };

        $failed = count($report->failures);
        $summary = match (true) {
            $report->checked === 0 => 'Nothing to check',
            $failed === 0 => "{$report->checked} ".$this->plural($report->checked, 'check').' passed',
            default => "{$failed} of {$report->checked} ".$this->plural($report->checked, 'check').' failed',
        };

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
