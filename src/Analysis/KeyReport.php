<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use JsonSerializable;

final readonly class KeyReport implements JsonSerializable
{
    /**
     * @param  list<KeyRow>  $rows
     */
    public function __construct(public array $rows) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['rows' => $this->rows];
    }
}
