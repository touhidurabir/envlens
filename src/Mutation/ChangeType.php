<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Mutation;

enum ChangeType: string
{
    case Add = 'add';
    case Remove = 'remove';
}
