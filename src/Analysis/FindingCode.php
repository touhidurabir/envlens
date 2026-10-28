<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

enum FindingCode: string
{
    case EnvOutsideConfig = 'env-outside-config';
    case MissingRequired = 'missing-required';
    case EmptyValue = 'empty-value';
    case DynamicKey = 'dynamic-key';
    case DuplicateKey = 'duplicate-key';
    case UnusedKey = 'unused-key';
    case MissingWithDefault = 'missing-with-default';
    case MissingOptional = 'missing-optional';
    case Syntax = 'syntax';
    case NoExample = 'no-example';
    case ExampleDrift = 'example-drift';
    case ScanWarning = 'scan-warning';

    public function severity(): Severity
    {
        return match ($this) {
            self::EnvOutsideConfig, self::MissingRequired, self::Syntax => Severity::Error,
            self::EmptyValue, self::DynamicKey, self::DuplicateKey, self::NoExample, self::ExampleDrift, self::ScanWarning => Severity::Warning,
            self::UnusedKey, self::MissingWithDefault, self::MissingOptional => Severity::Info,
        };
    }

    /** Fix order: lower comes first. Broken files and missing required keys before tidy-ups. */
    public function priority(): int
    {
        return match ($this) {
            self::Syntax => 1,
            self::MissingRequired => 2,
            self::EmptyValue => 3,
            self::EnvOutsideConfig => 4,
            self::DuplicateKey => 5,
            self::NoExample => 6,
            self::ExampleDrift => 7,
            self::DynamicKey => 8,
            self::ScanWarning => 9,
            self::UnusedKey => 10,
            self::MissingOptional => 11,
            self::MissingWithDefault => 12,
        };
    }

    /** One sentence on what the finding means, for the audit legend. */
    public function meaning(): string
    {
        return match ($this) {
            self::Syntax => 'a line in the env file could not be parsed',
            self::MissingRequired => 'config reads it with no default, it is declared required, and the file lacks it',
            self::EmptyValue => "set but empty: env() returns '' and the config default is not used",
            self::EnvOutsideConfig => 'env() called outside config/; it returns null once config is cached',
            self::DuplicateKey => 'defined more than once; the last one wins',
            self::NoExample => 'no example file tells teammates which keys to set',
            self::ExampleDrift => 'in the env file or the example file, but not both',
            self::DynamicKey => 'env() with a key built at runtime, which cannot be checked statically',
            self::ScanWarning => 'a PHP file could not be scanned',
            self::UnusedKey => 'defined, but nothing reads it',
            self::MissingOptional => 'not set and read without a default, but not declared required',
            self::MissingWithDefault => 'not set; config falls back to its default (usually fine)',
        };
    }
}
