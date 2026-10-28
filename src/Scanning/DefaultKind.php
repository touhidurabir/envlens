<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

enum DefaultKind: string
{
    case None = 'none';
    case Null = 'null';
    case Literal = 'literal';
    case NonLiteral = 'non-literal';
}
