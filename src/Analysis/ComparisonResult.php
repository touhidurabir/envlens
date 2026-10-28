<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use JsonSerializable;

final readonly class ComparisonResult implements JsonSerializable
{
    /**
     * @param  list<string>  $onlyInSource
     * @param  list<string>  $onlyInTarget
     * @param  list<string>  $different  present in both with different values; disjoint from $emptyInTarget (such keys appear only there)
     * @param  list<string>  $emptyInTarget  present in both, empty in target, non-empty in source
     * @param  list<string>  $missingFromBoth  required by code/config but absent from both
     * @param  list<string>  $same  present in both with the same value
     */
    public function __construct(
        public array $onlyInSource,
        public array $onlyInTarget,
        public array $different,
        public array $emptyInTarget,
        public array $missingFromBoth,
        public array $same = [],
    ) {}

    /** True when nothing differs between the files (keys with the same value only). */
    public function inSync(): bool
    {
        return $this->onlyInSource === [] && $this->onlyInTarget === [] && $this->different === []
            && $this->emptyInTarget === [] && $this->missingFromBoth === [];
    }

    /**
     * Counts per kind. Keys in both files fall in exactly one of different, empty-in-target or same.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $inBoth = count($this->different) + count($this->emptyInTarget) + count($this->same);

        return [
            'source_keys' => count($this->onlyInSource) + $inBoth,
            'target_keys' => count($this->onlyInTarget) + $inBoth,
            'same' => count($this->same),
            'different' => count($this->different),
            'only_in_source' => count($this->onlyInSource),
            'only_in_target' => count($this->onlyInTarget),
            'empty_in_target' => count($this->emptyInTarget),
            'missing_from_both' => count($this->missingFromBoth),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'only_in_source' => $this->onlyInSource,
            'only_in_target' => $this->onlyInTarget,
            'different' => $this->different,
            'empty_in_target' => $this->emptyInTarget,
            'missing_from_both' => $this->missingFromBoth,
            'same' => $this->same,
            'summary' => $this->summary(),
        ];
    }
}
