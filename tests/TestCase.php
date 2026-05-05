<?php

namespace Inkline\Linkwise\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Statamic\Providers\StatamicServiceProvider::class,
            \Inkline\Linkwise\ServiceProvider::class,
        ];
    }
}
