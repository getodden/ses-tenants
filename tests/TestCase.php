<?php

namespace Odden\SesTenants\Tests;

use Odden\SesTenants\SesTenantsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SesTenantsServiceProvider::class];
    }
}
