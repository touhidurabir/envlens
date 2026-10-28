<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Env;

enum EnvEntryType
{
    case Key;
    case Comment;
    case Blank;
    case Invalid;
}
