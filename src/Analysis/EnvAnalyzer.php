<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Scanning\DefaultKind;
use Touhidurabir\EnvLens\Scanning\EnvUsage;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Scanning\UsageSource;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\Glob;

/**
 * Masking: result objects never hold a sensitive raw value. `keys()` stores display values
 * (masked unless `$reveal` is true and the key is not sensitive); sensitive keys are always
 * masked. Literal config defaults of sensitive keys are masked inside usages.
 *
 * Required: a key is required only when the index says it is config-required (a Config usage
 * without a default, and no default anywhere) AND the team declared it, by listing it in the
 * example file or in `envlens.required`. Otherwise an absent key is optional.
 */
final class EnvAnalyzer
{
    /**
     * @param  list<string>  $externalPatterns  keys set by tooling outside the app (cleanup.keep)
     * @param  list<string>  $allowOutsideConfig  globs of relative paths allowed to call env()
     * @param  list<string>  $required  globs of keys declared required (envlens.required)
     */
    public function __construct(
        private readonly SensitivityDetector $sensitivity,
        private readonly array $externalPatterns,
        private readonly array $allowOutsideConfig,
        private readonly array $required = [],
    ) {}

    /**
     * @param  ?EnvFile  $example  the example file; keys it lists count as declared required
     */
    public function keys(EnvFile $file, UsageIndex $index, bool $reveal = false, ?EnvFile $example = null): KeyReport
    {
        $rows = [];
        $inFile = $file->keys();

        foreach ($inFile as $key) {
            $rows[] = new KeyRow(
                $key,
                $this->display($key, $file->get($key), $reveal),
                $this->sensitivity->isSensitive($key, $file->get($key)),
                $index->configPaths($key),
                count($index->codeUsages($key)),
                $this->statusInFile($key, $index),
                $this->sources($key, $index),
                ($file->get($key) ?? '') !== '',
            );
        }

        foreach ($this->missingKeys($file, $index) as $key) {
            $rows[] = new KeyRow(
                $key,
                null,
                $this->sensitivity->isSensitive($key),
                $index->configPaths($key),
                count($index->codeUsages($key)),
                match ($this->missingCode($key, $index, $example)) {
                    FindingCode::MissingRequired => KeyStatus::Missing,
                    FindingCode::MissingWithDefault => KeyStatus::MissingWithDefault,
                    default => KeyStatus::MissingOptional,
                },
                $this->sources($key, $index),
            );
        }

        return new KeyReport($rows);
    }

    /**
     * @param  ?EnvFile  $example  the example file; keys it lists count as declared required
     */
    public function compare(EnvFile $source, EnvFile $target, UsageIndex $index, ?EnvFile $example = null): ComparisonResult
    {
        $onlyInSource = [];
        $different = [];
        $emptyInTarget = [];
        $same = [];

        foreach ($source->keys() as $key) {
            if (! $target->has($key)) {
                $onlyInSource[] = $key;

                continue;
            }

            if ($target->isEmpty($key) && ! $source->isEmpty($key)) {
                $emptyInTarget[] = $key;
            } elseif ($source->get($key) !== $target->get($key)) {
                $different[] = $key;
            } else {
                $same[] = $key;
            }
        }

        $onlyInTarget = array_values(array_filter($target->keys(), static fn (string $k): bool => ! $source->has($k)));

        $missingFromBoth = array_values(array_filter(
            $index->keys(),
            fn (string $k): bool => ! $source->has($k) && ! $target->has($k) && $this->isRequired($k, $index, $example),
        ));

        return new ComparisonResult($onlyInSource, $onlyInTarget, $different, $emptyInTarget, $missingFromBoth, $same);
    }

    /**
     * @param  string  $exampleName  the example file's name, used in the no-example finding
     */
    public function audit(EnvFile $file, ?EnvFile $example, UsageIndex $index, string $exampleName = '.env.example'): AuditReport
    {
        $label = $this->label($file);
        $findings = [];

        foreach ($index->all() as $usage) {
            if ($usage->source === UsageSource::Code && ! Glob::matchesAny($this->allowOutsideConfig, $usage->file)) {
                $findings[] = $this->outsideConfig($usage);
            }
        }

        $missing = $this->missingKeys($file, $index);
        foreach ($missing as $key) {
            $findings[] = $this->missing($key, $index, $example, $file, $label);
        }

        foreach ($index->dynamic() as $usage) {
            $findings[] = $this->dynamic($usage);
        }

        foreach ($file->duplicates() as $key => $lines) {
            $findings[] = $this->duplicate((string) $key, $lines, $file);
        }

        foreach ($file->keys() as $key) {
            $status = $this->statusInFile($key, $index);

            if ($status === KeyStatus::Unused) {
                $findings[] = new Finding(
                    FindingCode::UnusedKey,
                    "{$key} is defined in {$label} but nothing references it",
                    $key,
                    $file->path(),
                    $this->firstLine($file, $key),
                    "{$label}:{$this->firstLine($file, $key)}",
                    'Remove it (php artisan env:cleanup --unused), or add it to envlens.cleanup.keep if something outside PHP reads it.',
                );
            } elseif ($status === KeyStatus::Used && $file->isEmpty($key) && $index->isUsed($key)) {
                $findings[] = new Finding(
                    FindingCode::EmptyValue,
                    "{$key} is empty in {$label}; env() returns '' and the config default is not used",
                    $key,
                    $file->path(),
                    $this->firstLine($file, $key),
                    $this->pathsDetail($index, $key),
                    "Set a value for {$key} in {$label}, or delete the line so the config default applies.",
                );
            }
        }

        foreach ($file->syntaxErrors() as $error) {
            $findings[] = new Finding(
                FindingCode::Syntax,
                "Syntax error in {$label} line {$error->line}: {$error->message}",
                null,
                $file->path(),
                $error->line,
                preg_replace('/^Failed to parse dotenv file\.\s*/', '', $error->message) ?? $error->message,
                "Fix line {$error->line} of {$label}.",
            );
        }

        if ($example === null) {
            $optional = count(array_filter($findings, static fn (Finding $f): bool => $f->code === FindingCode::MissingOptional));
            $keys = count($file->keys());

            $findings[] = new Finding(
                FindingCode::NoExample,
                "No {$exampleName}: teammates cannot tell which keys to set, and only envlens.required marks keys as required",
                null,
                null,
                null,
                "teammates get none of the {$keys} keys in {$label}; {$optional} keys read without a default count only as optional",
                "Create {$exampleName} with php artisan env:generate.",
            );
        } else {
            $exampleLabel = $this->label($example);

            foreach ($file->keys() as $key) {
                if (! $example->has($key)) {
                    $findings[] = new Finding(
                        FindingCode::ExampleDrift,
                        "{$key} is in {$label} but not in {$exampleLabel}",
                        $key,
                        $file->path(),
                        $this->firstLine($file, $key),
                        "in {$label}, not in {$exampleLabel}",
                        "Add it to {$exampleLabel}: php artisan env:sync.",
                    );
                }
            }

            foreach ($example->keys() as $key) {
                // Keys the app reads are already reported as missing-*.
                if (! $file->has($key) && ! in_array($key, $missing, true)) {
                    $findings[] = new Finding(
                        FindingCode::ExampleDrift,
                        "{$key} is in {$exampleLabel} but not in {$label}",
                        $key,
                        $example->path(),
                        $this->firstLine($example, $key),
                        "in {$exampleLabel}, not in {$label}",
                        "Add it to {$label}, or remove it from {$exampleLabel} (php artisan env:cleanup).",
                    );
                }
            }
        }

        foreach ($index->warnings() as $warning) {
            $findings[] = new Finding(FindingCode::ScanWarning, $warning, hint: 'Fix the file so EnvLens can scan it.');
        }

        $byFile = [];
        foreach ($index->reportable() as $usage) {
            $byFile[$usage->file][] = $this->maskUsage($usage);
        }
        ksort($byFile);

        return new AuditReport($byFile, $findings);
    }

    /**
     * @param  array<string, EnvFile>  $files  label => file; the first one is the primary file
     * @param  ?EnvFile  $example  the example file; keys it lists count as declared required
     * @param  bool  $reveal  show non-sensitive values in `values` instead of masking them
     */
    public function explain(string $key, array $files, UsageIndex $index, ?EnvFile $example = null, bool $reveal = false): Explanation
    {
        $presence = [];
        $values = [];
        foreach ($files as $label => $file) {
            $presence[$label] = ! $file->has($key) ? 'absent' : ($file->isEmpty($key) ? 'empty' : 'set');
            $values[$label] = $file->has($key) ? $this->display($key, $file->get($key), $reveal) : null;
        }

        $findings = [];
        $primaryLabel = array_key_first($files);
        $primary = $primaryLabel !== null ? $files[$primaryLabel] : null;

        foreach ($index->for($key) as $usage) {
            if ($usage->source === UsageSource::Code && ! Glob::matchesAny($this->allowOutsideConfig, $usage->file)) {
                $findings[] = $this->outsideConfig($usage);
            }
        }

        $dynamic = array_values(array_filter(
            $index->dynamic(),
            static fn (EnvUsage $usage): bool => $usage->dynamicPattern !== null && Glob::matches($usage->dynamicPattern, $key),
        ));
        foreach ($dynamic as $usage) {
            $findings[] = $this->dynamic($usage);
        }

        $status = null;

        if ($primary !== null) {
            $label = (string) $primaryLabel;

            if (! $primary->has($key)) {
                if ($index->isUsed($key)) {
                    $missing = $this->missing($key, $index, $example, $primary, $label);
                    $findings[] = $missing;
                    $status = match ($missing->code) {
                        FindingCode::MissingRequired => KeyStatus::Missing,
                        FindingCode::MissingWithDefault => KeyStatus::MissingWithDefault,
                        default => KeyStatus::MissingOptional,
                    };
                }
            } else {
                $status = $this->statusInFile($key, $index);

                if (isset($primary->duplicates()[$key])) {
                    $findings[] = $this->duplicate($key, $primary->duplicates()[$key], $primary);
                }

                if ($status === KeyStatus::Unused) {
                    $findings[] = new Finding(FindingCode::UnusedKey, "{$key} is defined in {$label} but nothing references it", $key, $primary->path(), $this->firstLine($primary, $key),
                        "{$label}:{$this->firstLine($primary, $key)}", 'Remove it (php artisan env:cleanup --unused), or add it to envlens.cleanup.keep if something outside PHP reads it.');
                } elseif ($status === KeyStatus::Used && $primary->isEmpty($key) && $index->isUsed($key)) {
                    $findings[] = new Finding(FindingCode::EmptyValue, "{$key} is empty in {$label}; env() returns '' and the config default is not used", $key, $primary->path(), $this->firstLine($primary, $key),
                        $this->pathsDetail($index, $key), "Set a value for {$key} in {$label}, or delete the line so the config default applies.");
                }
            }

            foreach (array_slice($files, 1, null, true) as $otherLabel => $other) {
                $isExample = $example !== null ? $other === $example : str_contains((string) $otherLabel, 'example');

                if (! $isExample) {
                    continue;
                }

                if ($primary->has($key) && ! $other->has($key)) {
                    $findings[] = new Finding(FindingCode::ExampleDrift, "{$key} is in {$label} but not in {$otherLabel}", $key, $primary->path(), $this->firstLine($primary, $key),
                        "in {$label}, not in {$otherLabel}", "Add it to {$otherLabel}: php artisan env:sync.");
                } elseif (! $primary->has($key) && $other->has($key) && ! $index->isUsed($key)) {
                    $findings[] = new Finding(FindingCode::ExampleDrift, "{$key} is in {$otherLabel} but not in {$label}", $key, $other->path(), $this->firstLine($other, $key),
                        "in {$otherLabel}, not in {$label}", "Add it to {$label}, or remove it from {$otherLabel} (php artisan env:cleanup).");
                }
            }
        }

        if ($status === null && in_array(true, array_map(static fn (string $s): bool => $s !== 'absent', $presence), true)) {
            $status = KeyStatus::Unused; // only in a non-primary file, and nothing reads it
        }

        $sensitive = $this->sensitivity->isSensitive($key);
        foreach ($files as $file) {
            $sensitive = $sensitive || $this->sensitivity->isSensitive($key, $file->get($key));
        }

        [$usages, $overridden] = $this->effectiveUsages($index->for($key));

        return new Explanation(
            $key,
            $sensitive,
            array_map($this->maskUsage(...), $usages),
            $presence,
            $findings,
            $status,
            $this->resolves($primary, $key, $usages, $dynamic),
            array_map($this->maskUsage(...), $overridden),
            $dynamic,
            $values,
            $status === null && $dynamic === [] ? $this->suggestions($key, $index, $files) : [],
        );
    }

    /**
     * Usages in display order (app config, code, vendor), split from vendor usages the app's own
     * config replaces: Laravel merges framework config under the app's, so a framework path the
     * app config also reads is dead.
     *
     * @param  list<EnvUsage>  $usages
     * @return array{list<EnvUsage>, list<EnvUsage>}
     */
    private function effectiveUsages(array $usages): array
    {
        $appPaths = [];
        foreach ($usages as $usage) {
            if ($usage->source === UsageSource::Config && $usage->configPath !== null) {
                $appPaths[$usage->configPath] = true;
            }
        }

        $rank = static fn (EnvUsage $u): int => match ($u->source) {
            UsageSource::Config => 0,
            UsageSource::Code => 1,
            UsageSource::Vendor => 2,
        };
        usort($usages, static fn (EnvUsage $a, EnvUsage $b): int => $rank($a) <=> $rank($b));

        $effective = [];
        $overridden = [];
        foreach ($usages as $usage) {
            $shadowed = $usage->source === UsageSource::Vendor && $usage->configPath !== null && isset($appPaths[$usage->configPath]);
            $shadowed ? $overridden[] = $usage : $effective[] = $usage;
        }

        return [$effective, $overridden];
    }

    /**
     * What env() hands the app for this key, from the primary file.
     *
     * @param  list<EnvUsage>  $usages
     * @param  list<EnvUsage>  $dynamic
     */
    private function resolves(?EnvFile $primary, string $key, array $usages, array $dynamic): string
    {
        if ($primary !== null && $primary->has($key)) {
            return $primary->isEmpty($key) ? 'empty' : 'value';
        }

        foreach ($usages as $usage) {
            if (in_array($usage->default, [DefaultKind::Literal, DefaultKind::NonLiteral], true)) {
                return 'default';
            }
        }

        return 'null';
    }

    /**
     * Up to three known keys spelled like $key (for typos).
     *
     * @param  array<string, EnvFile>  $files
     * @return list<string>
     */
    private function suggestions(string $key, UsageIndex $index, array $files): array
    {
        $known = $index->keys();
        foreach ($files as $file) {
            $known = array_merge($known, $file->keys());
        }

        $scores = [];
        $needle = strtoupper($key);
        foreach (array_unique($known) as $candidate) {
            $distance = levenshtein($needle, strtoupper($candidate));

            if ($distance <= max(2, intdiv(strlen($needle), 4)) || (strlen($needle) >= 4 && str_contains(strtoupper($candidate), $needle))) {
                $scores[$candidate] = $distance;
            }
        }

        asort($scores);

        return array_slice(array_map('strval', array_keys($scores)), 0, 3);
    }

    /** Config-required (see UsageIndex::isRequired) and declared by the team. */
    private function isRequired(string $key, UsageIndex $index, ?EnvFile $example): bool
    {
        return $index->isRequired($key)
            && (Glob::matchesAny($this->required, $key) || ($example !== null && $example->has($key)));
    }

    private function missingCode(string $key, UsageIndex $index, ?EnvFile $example): FindingCode
    {
        return match (true) {
            $this->isRequired($key, $index, $example) => FindingCode::MissingRequired,
            $index->isCodeOnly($key) => FindingCode::MissingOptional,
            $index->hasDefault($key) => FindingCode::MissingWithDefault,
            default => FindingCode::MissingOptional,
        };
    }

    private function missing(string $key, UsageIndex $index, ?EnvFile $example, EnvFile $file, string $label): Finding
    {
        $paths = $this->pathsSuffix($index, $key);
        $code = $this->missingCode($key, $index, $example);

        $message = match (true) {
            $code === FindingCode::MissingRequired => "{$key} is required by config{$paths} but missing from {$label}",
            $code === FindingCode::MissingWithDefault => "{$key} is missing from {$label} but has a default{$paths}",
            $index->isCodeOnly($key) => "{$key} is used in code but not set in {$label}",
            default => "{$key} is read without a default{$paths} but is optional (not in the example file or envlens.required); not set in {$label}",
        };

        $hint = match ($code) {
            FindingCode::MissingRequired => "Add {$key} to {$label}.",
            FindingCode::MissingWithDefault => "Nothing to do; set {$key} in {$label} to override the default.",
            default => "Set {$key} in {$label} if you use it; list it in the example file or envlens.required to make it required.",
        };

        return new Finding($code, $message, $key, $file->path(), null, $this->pathsDetail($index, $key), $hint);
    }

    /** The config paths that read a key, or where code reads it. */
    private function pathsDetail(UsageIndex $index, string $key): string
    {
        $paths = $index->configPaths($key);

        return $paths !== [] ? implode(', ', $paths) : 'read in code';
    }

    private function statusInFile(string $key, UsageIndex $index): KeyStatus
    {
        if ($index->isUsed($key) || $index->isProtectedByDynamic($key)) {
            return KeyStatus::Used;
        }

        return Glob::matchesAny($this->externalPatterns, $key) ? KeyStatus::External : KeyStatus::Unused;
    }

    /**
     * Where a key is read: app config, app code, vendor packages, or only through a dynamic key.
     *
     * @return list<string>
     */
    private function sources(string $key, UsageIndex $index): array
    {
        $sources = [];

        foreach ($index->for($key) as $usage) {
            $sources[] = match ($usage->source) {
                UsageSource::Config => 'app',
                UsageSource::Code => 'code',
                UsageSource::Vendor => 'vendor:'.($usage->package ?? 'unknown'),
            };
        }

        if ($sources === [] && $index->isProtectedByDynamic($key)) {
            $sources[] = 'dynamic';
        }

        return array_values(array_unique($sources));
    }

    /**
     * Used keys that the file does not define, sorted. Keys read only by background
     * packages (Laravel's own config, unless --vendor) are not reported.
     *
     * @return list<string>
     */
    private function missingKeys(EnvFile $file, UsageIndex $index): array
    {
        return array_values(array_filter(
            $index->keys(),
            static fn (string $k): bool => ! $file->has($k) && $index->isReportable($k),
        ));
    }

    private function display(string $key, ?string $value, bool $reveal): string
    {
        if ($reveal && ! $this->sensitivity->isSensitive($key, $value) && $value !== null) {
            return $value;
        }

        return $this->sensitivity->mask($value);
    }

    private function maskUsage(EnvUsage $usage): EnvUsage
    {
        if ($usage->key === null || $usage->defaultValue === null || ! $this->sensitivity->isSensitive($usage->key, $usage->defaultValue)) {
            return $usage;
        }

        return new EnvUsage(
            $usage->key,
            $usage->file,
            $usage->line,
            $usage->source,
            $usage->configPath,
            $usage->default,
            $this->sensitivity->mask($usage->defaultValue),
            $usage->dynamicPattern,
            $usage->package,
        );
    }

    private function outsideConfig(EnvUsage $usage): Finding
    {
        $what = $usage->key ?? 'a dynamic key';

        return new Finding(
            FindingCode::EnvOutsideConfig,
            "env() for {$what} is called outside config/ at {$usage->file}:{$usage->line}",
            $usage->key,
            $usage->file,
            $usage->line,
            "{$usage->file}:{$usage->line}",
            'Read it in a config file and use config() here: env() returns null once config is cached.',
        );
    }

    private function dynamic(EnvUsage $usage): Finding
    {
        $pattern = $usage->dynamicPattern !== null ? " (matches {$usage->dynamicPattern})" : '';

        return new Finding(
            FindingCode::DynamicKey,
            "env() is called with a non-literal key{$pattern} at {$usage->file}:{$usage->line}",
            null,
            $usage->file,
            $usage->line,
            $usage->dynamicPattern !== null ? "matches {$usage->dynamicPattern}" : 'any key',
            'Use literal keys, or list the possible keys in envlens.required so they are checked.',
        );
    }

    /**
     * @param  list<int>  $lines
     */
    private function duplicate(string $key, array $lines, EnvFile $file): Finding
    {
        return new Finding(
            FindingCode::DuplicateKey,
            "{$key} is defined ".count($lines).' times in '.$this->label($file).' (lines '.implode(', ', $lines).'); the last one wins',
            $key,
            $file->path(),
            $lines[0],
            $this->label($file).' lines '.implode(', ', $lines),
            "Keep one {$key} line in ".$this->label($file).'; the last one wins.',
        );
    }

    private function pathsSuffix(UsageIndex $index, string $key): string
    {
        $paths = $index->configPaths($key);

        return $paths === [] ? '' : ' ('.implode(', ', $paths).')';
    }

    private function firstLine(EnvFile $file, string $key): ?int
    {
        foreach ($file->lines() as $line) {
            if ($line->key === $key) {
                return $line->line;
            }
        }

        return null;
    }

    private function label(EnvFile $file): string
    {
        $path = $file->path();

        return $path === null ? '.env' : basename($path);
    }
}
