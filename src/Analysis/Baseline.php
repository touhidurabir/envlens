<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use RuntimeException;
use Touhidurabir\EnvLens\Exceptions\InvalidBaseline;

/**
 * Findings a team has accepted for now, so env:audit --ci blocks only new ones. Entries hold a code,
 * a key and (for env() calls) a file: no line numbers, so moving code does not invalidate them, and
 * never a value. Secrets, syntax errors and scan failures can never be accepted.
 */
final class Baseline
{
    public const FILE = 'envlens-baseline.json';

    /**
     * @param  list<array{code: string, key: ?string, file?: string}>  $entries
     */
    private function __construct(private readonly array $entries) {}

    public static function accepts(FindingCode $code): bool
    {
        return $code === FindingCode::UndeclaredKey || $code === FindingCode::EnvOutsideConfig;
    }

    /**
     * @param  list<Finding>  $findings
     */
    public static function fromFindings(array $findings): self
    {
        $entries = [];

        foreach ($findings as $finding) {
            if (self::accepts($finding->code)) {
                $entry = self::entry($finding);
                $entries[self::fingerprint($entry)] = $entry;
            }
        }

        usort($entries, static fn (array $a, array $b): int => self::fingerprint($a) <=> self::fingerprint($b));

        return new self($entries);
    }

    /** Null when the file does not exist. */
    public static function load(string $path): ?self
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['findings'] ?? null)) {
            throw InvalidBaseline::at($path);
        }

        $entries = [];

        foreach ($data['findings'] as $raw) {
            $code = is_array($raw) && is_string($raw['code'] ?? null) ? FindingCode::tryFrom($raw['code']) : null;

            if ($code === null || ! self::accepts($code)) {
                throw InvalidBaseline::at($path);
            }

            $entry = ['code' => $code->value, 'key' => is_string($raw['key'] ?? null) ? $raw['key'] : null];

            if (is_string($raw['file'] ?? null)) {
                $entry['file'] = $raw['file'];
            }

            $entries[] = $entry;
        }

        return new self($entries);
    }

    /**
     * @return list<array{code: string, key: ?string, file?: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function save(string $path): void
    {
        $json = json_encode(['schema' => 1, 'findings' => $this->entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException('Could not write '.basename($path).'.');
        }
    }

    /**
     * Splits findings into those still reported and those this baseline accepts, and turns entries
     * nothing matched any more into info findings.
     *
     * @param  list<Finding>  $findings
     * @return array{kept: list<Finding>, accepted: int, stale: list<Finding>}
     */
    public function apply(array $findings): array
    {
        $known = [];
        foreach ($this->entries as $entry) {
            $known[self::fingerprint($entry)] = $entry;
        }

        $kept = [];
        $accepted = 0;
        $matched = [];

        foreach ($findings as $finding) {
            $fingerprint = self::accepts($finding->code) ? self::fingerprint(self::entry($finding)) : null;

            if ($fingerprint !== null && isset($known[$fingerprint])) {
                $accepted++;
                $matched[$fingerprint] = true;
            } else {
                $kept[] = $finding;
            }
        }

        $stale = [];
        foreach (array_diff_key($known, $matched) as $entry) {
            $stale[] = new Finding(
                FindingCode::StaleBaseline,
                $entry['code'].' for '.($entry['key'] ?? $entry['file'] ?? 'a key').' is in '.self::FILE.' but is no longer reported',
                $entry['key'],
                $entry['file'] ?? null,
                null,
                $entry['code'].' no longer reported',
                'Run php artisan env:audit --ci --update-baseline to remove it.',
            );
        }

        return ['kept' => $kept, 'accepted' => $accepted, 'stale' => $stale];
    }

    /**
     * @return array{code: string, key: ?string, file?: string}
     */
    private static function entry(Finding $finding): array
    {
        $entry = ['code' => $finding->code->value, 'key' => $finding->key];

        if ($finding->code === FindingCode::EnvOutsideConfig && $finding->file !== null) {
            $entry['file'] = str_replace('\\', '/', $finding->file);
        }

        return $entry;
    }

    /**
     * @param  array{code: string, key: ?string, file?: string}  $entry
     */
    private static function fingerprint(array $entry): string
    {
        return $entry['code']."\0".$entry['key']."\0".($entry['file'] ?? '');
    }
}
