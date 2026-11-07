<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Touhidurabir\EnvLens\EnvLensServiceProvider;

abstract class TestCase extends Orchestra
{
    /** The package's own CI sets these; each test starts without them and sets what it needs. */
    protected function setUp(): void
    {
        putenv('CI');
        putenv('GITHUB_ACTIONS');

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [EnvLensServiceProvider::class];
    }
}
