<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Mutation;

use JsonSerializable;

final readonly class Plan implements JsonSerializable
{
    /**
     * @param  list<Change>  $changes
     */
    public function __construct(public array $changes) {}

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return list<Change>
     */
    public function adds(): array
    {
        return $this->ofType(ChangeType::Add);
    }

    /**
     * @return list<Change>
     */
    public function removes(): array
    {
        return $this->ofType(ChangeType::Remove);
    }

    /**
     * The value is deliberately never serialized.
     *
     * @return list<array{type: string, key: string, value_copied: bool}>
     */
    public function jsonSerialize(): array
    {
        return array_map(static fn (Change $c): array => [
            'type' => $c->type->value,
            'key' => $c->key,
            'value_copied' => $c->valueCopied,
        ], $this->changes);
    }

    /**
     * @return list<Change>
     */
    private function ofType(ChangeType $type): array
    {
        return array_values(array_filter($this->changes, static fn (Change $c): bool => $c->type === $type));
    }
}
