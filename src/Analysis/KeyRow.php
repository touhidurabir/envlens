<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use JsonSerializable;

/**
 * `$value` is the display value, already masked by the analyzer (null when the key is absent
 * from the file). The raw value of a sensitive key is never stored here.
 *
 * `$sources` says where the key is read: `app` (application config), `code` (env() in
 * application code), `vendor:<package>` (a vendor package's config) or `dynamic` (only
 * matched by a dynamically built key). Empty when nothing reads it.
 *
 * `$hasValue` is true when the file defines the key with a non-empty value.
 */
final readonly class KeyRow implements JsonSerializable
{
    /**
     * @param  list<string>  $configPaths
     * @param  list<string>  $sources
     */
    public function __construct(
        public string $key,
        public ?string $value,
        public bool $sensitive,
        public array $configPaths,
        public int $codeCount,
        public KeyStatus $status,
        public array $sources = [],
        public bool $hasValue = false,
    ) {}

    /** True for keys defined in the inspected file (as opposed to the missing statuses). */
    public function inFile(): bool
    {
        return ! in_array($this->status, [KeyStatus::Missing, KeyStatus::MissingWithDefault, KeyStatus::MissingOptional], true);
    }

    /**
     * True when the key has no value and something reads it without a fallback: empty in the
     * file (env() then returns '' and the config default is not used) or absent with no default.
     */
    public function needsValue(): bool
    {
        return ! $this->hasValue
            && in_array($this->status, [KeyStatus::Used, KeyStatus::Missing, KeyStatus::MissingOptional], true);
    }

    /** True when every place that reads the key is vendor config. */
    public function vendorOnly(): bool
    {
        if ($this->sources === []) {
            return false;
        }

        foreach ($this->sources as $source) {
            if (! str_starts_with($source, 'vendor:')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'sensitive' => $this->sensitive,
            'in_file' => $this->inFile(),
            'has_value' => $this->hasValue,
            'source' => $this->sources,
            'config_paths' => $this->configPaths,
            'code_count' => $this->codeCount,
            'status' => $this->status->value,
        ];
    }
}
