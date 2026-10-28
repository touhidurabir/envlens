<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

enum KeyStatus: string
{
    case Used = 'used';
    case Unused = 'unused';
    case External = 'external';
    case Missing = 'missing';
    case MissingWithDefault = 'missing-with-default';
    case MissingOptional = 'missing-optional';
}
