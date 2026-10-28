<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Mutation;

/** Why an added key gets the value it does. */
enum ChangeReason: string
{
    case Secret = 'secret';
    case Copied = 'copied';
    case ConfigDefault = 'config-default';
    case NoDefault = 'no-default';
    case SkipDefault = 'skip-default';
    case DefaultsOff = 'defaults-off';

    public function label(): string
    {
        return match ($this) {
            self::Secret => 'secret: values never copied',
            self::Copied => 'value copied',
            self::ConfigDefault => 'config default',
            self::NoDefault => 'no literal config default',
            self::SkipDefault => 'skipped (generate.skip_defaults)',
            self::DefaultsOff => 'defaults off (--no-defaults)',
        };
    }
}
