<?php

namespace Arturrossbach\Linkwise\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Statamic\Providers\StatamicServiceProvider::class,
            \Arturrossbach\Linkwise\ServiceProvider::class,
        ];
    }
}
