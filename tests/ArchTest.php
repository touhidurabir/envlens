<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Console\EnvLensCommand;

arch('all classes use strict types')
    ->expect('Touhidurabir\EnvLens')
    ->toUseStrictTypes();

arch('only the console layer touches Illuminate Console or Laravel Prompts')
    ->expect(['Illuminate\Console', 'Laravel\Prompts'])
    ->not->toBeUsedIn([
        'Touhidurabir\EnvLens\Analysis',
        'Touhidurabir\EnvLens\Env',
        'Touhidurabir\EnvLens\Exceptions',
        'Touhidurabir\EnvLens\Mutation',
        'Touhidurabir\EnvLens\Scanning',
        'Touhidurabir\EnvLens\Security',
        'Touhidurabir\EnvLens\Support',
        'Touhidurabir\EnvLens\Facades',
    ]);

arch('no debugging helpers are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('commands extend the EnvLens base command')
    ->expect('Touhidurabir\EnvLens\Console')
    ->classes
    ->toExtend(EnvLensCommand::class)
    ->ignoring(EnvLensCommand::class);
