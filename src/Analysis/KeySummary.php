<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use JsonSerializable;

/** Counts over a set of key rows (the rows env:keys lists). */
final readonly class KeySummary implements JsonSerializable
{
    public function __construct(
        public int $total,
        public int $withValue,
        public int $needValue,
        public int $required,
        public int $sensitive,
    ) {}

    /**
     * @param  list<KeyRow>  $rows
     */
    public static function of(array $rows): self
    {
        $count = static fn (callable $test): int => count(array_filter($rows, $test));

        return new self(
            count($rows),
            $count(static fn (KeyRow $row): bool => $row->hasValue),
            $count(static fn (KeyRow $row): bool => $row->needsValue()),
            $count(static fn (KeyRow $row): bool => $row->status === KeyStatus::Missing),
            $count(static fn (KeyRow $row): bool => $row->sensitive),
        );
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return [
            'total' => $this->total,
            'with_value' => $this->withValue,
            'need_value' => $this->needValue,
            'required_missing' => $this->required,
            'sensitive' => $this->sensitive,
            'not_sensitive' => $this->total - $this->sensitive,
        ];
    }
}
