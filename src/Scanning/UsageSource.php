<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

enum UsageSource: string
{
    case Config = 'config';
    case Code = 'code';
    case Vendor = 'vendor';
}
