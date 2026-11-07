<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console;

use Touhidurabir\EnvLens\Analysis\AuditReport;
use Touhidurabir\EnvLens\Analysis\Baseline;
use Touhidurabir\EnvLens\Analysis\Finding;
use Touhidurabir\EnvLens\Analysis\FindingCode;
use Touhidurabir\EnvLens\Analysis\Severity;
use Touhidurabir\EnvLens\Console\Concerns\WritesAnnotations;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Exceptions\InvalidBaseline;
use Touhidurabir\EnvLens\Scanning\UsageSource;
use Touhidurabir\EnvLens\Support\CiEnvironment;

use function Termwind\render;
use function Termwind\renderUsing;

final class AuditCommand extends EnvLensCommand
{
    use WritesAnnotations;

    /** How many steps "Fix first" lists before pointing at the findings. */
    private const STEPS = 5;

    protected $signature = 'env:audit
        {--file= : Env file to audit (default: envlens.files.source)}
        {--example= : Example env file (default: envlens.files.example)}
        {--vendor : Also scan the config of every vendor package and report framework usage in full}
        {--fail-on=error : Exit 1 at this severity or worse (error|warning|info|none)}
        {--ci : Check config and code against the example file alone; needs no .env}
        {--update-baseline : With --ci, accept current findings in envlens-baseline.json so only new ones block}
        {--json : Output JSON}
        {--path= : Project base path}';

    protected $description = 'Audit env usage and env files (use -v to list info findings)';

    protected function perform(): int
    {
        $failOn = $this->stringOption('fail-on', 'error');
        $level = Severity::tryFrom($failOn);

        if ($level === null && $failOn !== 'none') {
            $this->error("Invalid --fail-on value [{$failOn}]. Use error, warning, info or none.");

            return 2;
        }

        if ($this->option('ci')) {
            return $this->performCi($failOn, $level);
        }

        if ($this->option('update-baseline')) {
            $this->error('--update-baseline needs --ci.');

            return 2;
        }

        $file = $this->envFile($this->stringOption('file', $this->sourceName()));
        $exampleName = $this->stringOption('example', $this->exampleName());
        // A file you named has to exist; the default example file is allowed not to, and is reported.
        $example = $this->envFile($exampleName, $this->option('example') !== null);
        $exampleFile = $example->path() !== null && is_file($example->path()) ? $example : null;

        // Scanner warnings become scan-warning findings, so they are not printed separately.
        $report = $this->lens()->analyzer()->audit($file, $exampleFile, $this->index(false), basename($exampleName));
        $exit = $level !== null && $report->has($level) ? 1 : 0;

        if ($this->wantsJson()) {
            return $this->json('env:audit', $report, $exit);
        }

        $label = $this->label($file);
        $against = $exampleFile !== null ? " against <fg=cyan>{$this->label($exampleFile)}</>" : ' <fg=yellow>(no example file)</>';

        return $this->renderReport($report, $file, "Auditing <fg=cyan>{$label}</>{$against}", '', $exit, $failOn);
    }

    /** The CI gate: the example file is the contract, and nothing reads a real env file. */
    private function performCi(string $failOn, ?Severity $level): int
    {
        if ($this->option('file') !== null) {
            $this->error('--ci checks the example file, so it cannot be combined with --file.');

            return 2;
        }

        $example = $this->envFile($this->stringOption('example', $this->exampleName()));
        $index = $this->index(false);

        if (! $index->hasConfigUsages()) {
            $this->error('No env() reads were found in config, so there is nothing to check. Set envlens.scan.config_paths if your config lives elsewhere.');

            return 2;
        }

        $report = $this->lens()->analyzer()->contract($example, $index);
        $baselinePath = $this->basePath().'/'.Baseline::FILE;

        if ($this->option('update-baseline')) {
            return $this->updateBaseline($report, $baselinePath);
        }

        try {
            $baseline = Baseline::load($baselinePath);
        } catch (InvalidBaseline $e) {
            $this->error($e->getMessage());

            return 2;
        }

        if ($baseline !== null) {
            $result = $baseline->apply($report->findings);
            $report = new AuditReport($report->usagesByFile, [...$result['kept'], ...$result['stale']], $result['accepted']);
        }

        $exit = $level !== null && $report->has($level) ? 1 : 0;

        if ($this->wantsJson()) {
            return $this->json('env:audit', $report, $exit);
        }

        $this->renderReport($report, $example, "Checking <fg=cyan>{$this->label($example)}</> as the contract", ' · no .env needed', $exit, $failOn);

        if ($exit === 1 && $this->acceptable($report) !== []) {
            $this->line('  <fg=gray>To accept these for now and block only new ones:</> php artisan env:audit --ci --update-baseline');
            $this->newLine();
        }

        return $exit;
    }

    /** Errors first: GitHub shows only the first few of each severity per step. */
    private function annotations(AuditReport $report): void
    {
        if (! CiEnvironment::isGitHubActions()) {
            return;
        }

        foreach ([Severity::Error, Severity::Warning] as $severity) {
            foreach ($report->findings as $finding) {
                if ($finding->severity() === $severity) {
                    $this->annotate(
                        $severity === Severity::Error ? 'error' : 'warning',
                        $finding->code->value,
                        $finding->message,
                        $finding->file !== null ? $this->relativePath($finding->file) : null,
                        $finding->line,
                    );
                }
            }
        }
    }

    private function updateBaseline(AuditReport $report, string $path): int
    {
        if ($this->lens()->guard()->isProduction($this->basePath())) {
            $this->error('Refusing to update the baseline in production: it belongs in the repository.');

            return 1;
        }

        $accepted = $this->acceptable($report);
        $blocking = array_filter($report->findings, static fn (Finding $f): bool => $f->severity() === Severity::Error && ! Baseline::accepts($f->code));

        Baseline::fromFindings($accepted)->save($path);

        if ($this->wantsJson()) {
            return $this->json('env:audit', ['baseline' => Baseline::FILE, 'recorded' => count($accepted), 'not_recorded' => count($blocking)]);
        }

        $count = count($accepted);
        renderUsing($this->output);
        $this->newLine();
        render(<<<HTML
            <div class="mx-2">
                <span class="px-1 bg-green text-black font-bold">DONE</span>
                <span class="ml-1">Recorded {$count} {$this->plural($count, 'finding')} in {$this->relativePath($path)}.</span>
            </div>
        HTML);

        if ($blocking !== []) {
            $codes = implode(', ', array_unique(array_map(static fn (Finding $f): string => $f->code->value, $blocking)));
            $this->line('  <fg=yellow>Not recorded: '.count($blocking).' '.$this->plural(count($blocking), 'finding')." that must be fixed ({$codes}).</>");
        }

        $this->line('  <fg=gray>Commit it, so CI blocks only new findings:</> git add '.$this->relativePath($path));
        $this->newLine();

        return 0;
    }

    /**
     * @return list<Finding>
     */
    private function acceptable(AuditReport $report): array
    {
        return array_values(array_filter($report->findings, static fn (Finding $f): bool => Baseline::accepts($f->code)));
    }

    private function renderReport(AuditReport $report, EnvFile $file, string $title, string $suffix, int $exit, string $failOn): int
    {
        // Termwind writes to stdout until told otherwise; send it through this command's output.
        renderUsing($this->output);

        $label = $this->label($file);
        $groups = $this->groups($report);

        $this->annotations($report);

        $this->header($report, $title, $suffix);
        $this->verdict($report, $exit, $failOn);

        if ($report->baselined > 0) {
            $this->line("  <fg=gray>{$report->baselined} accepted by ".Baseline::FILE.'</>');
            $this->newLine();
        }

        if ($groups === []) {
            $this->line('  <fg=green>No issues found.</>');
            $this->newLine();

            return $exit;
        }

        $this->fixFirst($groups, $file, $label);
        $this->legend($groups);
        $this->findings($groups);

        return $exit;
    }

    /**
     * Findings grouped by code, most severe first, then in fix order.
     *
     * @return array<string, list<Finding>>
     */
    private function groups(AuditReport $report): array
    {
        $findings = $report->findings;
        usort($findings, static fn (Finding $a, Finding $b): int => $a->code->priority() <=> $b->code->priority());

        /** @var array<string, list<Finding>> $groups */
        $groups = [];
        foreach ($findings as $finding) {
            $groups[$finding->code->value][] = $finding;
        }

        uksort($groups, static function (string $a, string $b) use ($groups): int {
            return [self::rank($groups[$a][0]->severity()), FindingCode::from($a)->priority()]
                <=> [self::rank($groups[$b][0]->severity()), FindingCode::from($b)->priority()];
        });

        return $groups;
    }

    private function header(AuditReport $report, string $title, string $suffix): void
    {
        $config = [];
        $code = [];

        foreach ($report->usagesByFile as $path => $usages) {
            foreach ($usages as $usage) {
                $usage->source === UsageSource::Code ? $code[$path] = true : $config[$path] = true;
            }
        }

        $this->newLine();
        $this->line("  {$title} · ".count($config).' config '.$this->plural(count($config), 'file').', '
            .count($code).' code '.$this->plural(count($code), 'file').' scanned'.$suffix);
    }

    private function verdict(AuditReport $report, int $exit, string $failOn): void
    {
        $counts = $report->summary();

        [$badge, $color] = match (true) {
            $exit === 1 => ['FAIL', 'red'],
            $counts['error'] + $counts['warning'] > 0 => ['WARN', 'yellow'],
            default => ['PASS', 'green'],
        };

        $summary = "{$counts['error']} ".$this->plural($counts['error'], 'error')
            ." · {$counts['warning']} ".$this->plural($counts['warning'], 'warning')
            ." · {$counts['info']} info";

        render(<<<HTML
            <div class="mx-2 my-1">
                <span class="px-1 bg-{$color} text-black font-bold">{$badge}</span>
                <span class="ml-1">{$summary}</span>
                <span class="ml-1 text-gray">(exit {$exit}, --fail-on={$failOn})</span>
            </div>
        HTML);
    }

    /**
     * The first few things to fix, one step per kind of problem, most important first.
     *
     * @param  array<string, list<Finding>>  $groups
     */
    private function fixFirst(array $groups, EnvFile $file, string $label): void
    {
        $steps = [];

        foreach ($groups as $code => $findings) {
            foreach ($this->steps(FindingCode::from($code), $findings, $file, $label) as $priority => $step) {
                $steps[] = [$priority, $step];
            }
        }

        if ($steps === []) {
            return;
        }

        usort($steps, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $this->line('  <options=bold>Fix first</>');

        foreach (array_slice($steps, 0, self::STEPS) as $i => [, $step]) {
            $this->line('  <fg=gray>'.($i + 1).'.</> '.$step);
        }

        if (count($steps) > self::STEPS) {
            $more = count($steps) - self::STEPS;
            $this->line("  <fg=gray>+{$more} more ".$this->plural($more, 'step').': see the findings below.</>');
        }

        $this->newLine();
    }

    /**
     * The fix steps for one kind of finding, keyed by priority. Info findings are not steps,
     * except unused keys, which are worth tidying.
     *
     * @param  list<Finding>  $findings
     * @return array<int, string>
     */
    private function steps(FindingCode $code, array $findings, EnvFile $file, string $label): array
    {
        $count = count($findings);
        $keys = $this->names(array_map(static fn (Finding $f): string => (string) $f->key, $findings));
        $places = $this->names(array_map(static fn (Finding $f): string => (string) $f->detail, $findings));
        $priority = $code->priority() * 10;

        if ($code === FindingCode::ExampleDrift) {
            $toExample = array_values(array_filter($findings, static fn (Finding $f): bool => $f->file === $file->path()));
            $fromExample = array_values(array_filter($findings, static fn (Finding $f): bool => $f->file !== $file->path()));
            $steps = [];

            if ($toExample !== []) {
                $n = count($toExample);
                $steps[$priority] = "Add {$n} ".$this->plural($n, 'key').' from '.$label.' to the example file: <fg=cyan>php artisan env:sync</>.';
            }
            if ($fromExample !== []) {
                $n = count($fromExample);
                $names = $this->names(array_map(static fn (Finding $f): string => (string) $f->key, $fromExample));
                $steps[$priority + 1] = "Review {$n} ".$this->plural($n, 'key')." only in the example file ({$names}): add them to {$label}, or remove them with <fg=cyan>php artisan env:cleanup</>.";
            }

            return $steps;
        }

        $step = match ($code) {
            FindingCode::ExampleSecret => "Blank {$count} real-looking ".$this->plural($count, 'secret')." in the example file ({$places}) and rotate ".($count === 1 ? 'it' : 'them').': values stay in git history.',
            FindingCode::UndeclaredKey => $count === 1
                ? "Add {$findings[0]->key} to the example file: config reads it with no default ({$findings[0]->detail})."
                : "Add {$count} keys to the example file ({$keys}): config reads them with no default.",
            FindingCode::Syntax => "Fix {$count} syntax ".$this->plural($count, 'error')." in {$label} (".$this->names(array_map(static fn (Finding $f): string => 'line '.$f->line, $findings)).').',
            FindingCode::MissingRequired => $count === 1
                ? "Add {$findings[0]->key} to {$label}: required by {$findings[0]->detail}, no default."
                : "Add {$count} required keys to {$label} ({$keys}): config reads them with no default.",
            FindingCode::EmptyValue => $count === 1
                ? "Set a value for {$findings[0]->key} in {$label} (it is empty), or delete it so the config default applies."
                : "Set values for {$count} empty keys in {$label} ({$keys}), or delete them so config defaults apply.",
            FindingCode::EnvOutsideConfig => "Move env() out of {$count} ".$this->plural($count, 'place')." ({$places}) into config and use config(): env() returns null once <fg=cyan>php artisan config:cache</> runs.",
            FindingCode::DuplicateKey => $count === 1
                ? "Remove the duplicate {$findings[0]->key} ({$findings[0]->detail}; the last one wins)."
                : "Remove {$count} duplicated keys in {$label} ({$keys}; the last one wins).",
            FindingCode::NoExample => "Create an example file ({$findings[0]->detail}): <fg=cyan>php artisan env:generate</>.",
            FindingCode::DynamicKey => "Review {$count} dynamic env() ".$this->plural($count, 'key').' ('.$this->names(array_map(static fn (Finding $f): string => $f->file.':'.$f->line, $findings)).'): EnvLens cannot check '.($count === 1 ? 'it' : 'them').'.',
            FindingCode::ScanWarning => "Fix {$count} ".$this->plural($count, 'file').' EnvLens could not scan.',
            FindingCode::UnusedKey => "Remove {$count} unused ".$this->plural($count, 'key')." ({$keys}): <fg=cyan>php artisan env:cleanup --unused</>.",
            default => null,
        };

        return $step === null ? [] : [$priority => $step];
    }

    /**
     * What each code shown means.
     *
     * @param  array<string, list<Finding>>  $groups
     */
    private function legend(array $groups): void
    {
        $width = max(0, ...array_map('strlen', array_keys($groups)));

        $this->line('  <options=bold>What the codes mean</>');

        foreach ($groups as $code => $findings) {
            $color = $this->color($findings[0]->severity());
            $this->line("  <fg={$color}>".str_pad($code, $width).'</>  <fg=gray>'.FindingCode::from($code)->meaning().'</>');
        }
    }

    /**
     * Each group under a severity badge, one line per finding. Info groups are counted
     * instead of listed unless -v.
     *
     * @param  array<string, list<Finding>>  $groups
     */
    private function findings(array $groups): void
    {
        foreach ($groups as $code => $findings) {
            $severity = $findings[0]->severity();
            $count = count($findings);
            $badge = match ($severity) {
                Severity::Error => 'ERROR',
                Severity::Warning => 'WARN',
                Severity::Info => 'INFO',
            };
            $color = $this->color($severity);

            render(<<<HTML
                <div class="mx-2 mt-1">
                    <span class="px-1 bg-{$color} text-black font-bold">{$badge}</span>
                    <span class="ml-1 font-bold">{$code}</span>
                    <span class="ml-1 text-gray">({$count})</span>
                </div>
            HTML);

            if ($severity === Severity::Info && ! $this->output->isVerbose()) {
                $this->line("  <fg=gray>{$count} ".$this->plural($count, 'finding').' (run with -v to list)</>');

                continue;
            }

            foreach ($findings as $finding) {
                $this->item($finding);
            }
        }

        $this->newLine();
    }

    private function item(Finding $finding): void
    {
        $subject = $finding->key ?? match ($finding->code) {
            FindingCode::Syntax => $this->label(null, $finding->file).':'.$finding->line,
            FindingCode::DynamicKey => $finding->file.':'.$finding->line,
            default => null,
        };

        if ($subject === null || $finding->detail === null) {
            $this->line('  '.($subject ?? $finding->message).($subject === null && $finding->detail !== null ? "\n  <fg=gray>{$finding->detail}</>" : ''));

            return;
        }

        $this->components->twoColumnDetail($subject, '<fg=gray>'.$this->shorten($finding->detail).'</>');
    }

    /** A long list of config paths becomes its first path and a count, so the line fits. */
    private function shorten(string $detail): string
    {
        $parts = explode(', ', $detail);

        return count($parts) > 1 && strlen($detail) > 45 ? $parts[0].', +'.(count($parts) - 1).' more' : $detail;
    }

    /**
     * Up to three names, then "+N more".
     *
     * @param  list<string>  $names
     */
    private function names(array $names): string
    {
        $names = array_values(array_unique($names));
        $shown = implode(', ', array_slice($names, 0, 3));

        return count($names) > 3 ? $shown.', +'.(count($names) - 3).' more' : $shown;
    }

    private function label(?EnvFile $file, ?string $path = null): string
    {
        $path = $file !== null ? $file->path() : $path;

        return $path === null ? '.env' : basename($path);
    }

    private function color(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'red',
            Severity::Warning => 'yellow',
            Severity::Info => 'gray',
        };
    }

    private static function rank(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 0,
            Severity::Warning => 1,
            Severity::Info => 2,
        };
    }

    private function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }
}
