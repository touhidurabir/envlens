<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Touhidurabir\EnvLens\EnvLensServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [EnvLensServiceProvider::class];
    }
}
