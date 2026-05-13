<?php

namespace Arturrossbach\Linkwise\Tests;

use Illuminate\Routing\Router;
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

    /**
     * Register the addon's CP routes directly into Laravel's route table so
     * Feature-tests can hit them via {@see route()} or `postJson()`.
     *
     * Statamic's real CP-route boot (`Statamic::additionalCpRoutes()` via
     * `vendor/statamic/cms/routes/cp.php`) doesn't run in Orchestra Testbench's
     * env without a substantial Stache/CP-prefix dance. For characterisation
     * tests we don't need Statamic's CP middleware stack — we test controller
     * behaviour, not auth — so we side-load the routes file directly. Tests
     * combine this with `$this->withoutMiddleware()` to bypass `can:manage
     * linkwise` etc.
     */
    protected function defineRoutes($router): void
    {
        require __DIR__.'/../routes/cp.php';
    }
}
