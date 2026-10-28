<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use JsonSerializable;

/**
 * `$detail` is a short description for one line of output (config paths, location, ...);
 * `$hint` says how to fix it.
 */
final readonly class Finding implements JsonSerializable
{
    public function __construct(
        public FindingCode $code,
        public string $message,
        public ?string $key = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $detail = null,
        public ?string $hint = null,
    ) {}

    public function severity(): Severity
    {
        return $this->code->severity();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity()->value,
            'message' => $this->message,
            'key' => $this->key,
            'file' => $this->file,
            'line' => $this->line,
            'detail' => $this->detail,
            'hint' => $this->hint,
        ];
    }
}
