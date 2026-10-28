<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

use JsonSerializable;

final readonly class EnvUsage implements JsonSerializable
{
    public function __construct(
        public ?string $key,
        public string $file,
        public int $line,
        public UsageSource $source,
        public ?string $configPath,
        public DefaultKind $default,
        public ?string $defaultValue,
        public ?string $dynamicPattern,
        public ?string $package = null,
    ) {}

    public function isDynamic(): bool
    {
        return $this->key === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'file' => $this->file,
            'line' => $this->line,
            'source' => $this->source->value,
            'config_path' => $this->configPath,
            'default' => $this->default->value,
            'default_value' => $this->defaultValue,
            'dynamic_pattern' => $this->dynamicPattern,
            'package' => $this->package,
        ];
    }
}
