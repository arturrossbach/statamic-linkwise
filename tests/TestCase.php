<?php

namespace Arturrossbach\Linkwise\Tests;

use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Register the `Statamic` facade alias the same way
     * {@see \Statamic\Testing\AddonTestCase::getPackageAliases()} does.
     * Statamic's CP layout view (`statamic::layout`) uses the bare
     * `Statamic` alias — without this, any Inertia render that falls
     * through to the HTML shell crashes with "Class Statamic not found".
     * Required for Feature tests that exercise Inertia renderers.
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Statamic' => \Statamic\Statamic::class,
        ];
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Statamic\Providers\StatamicServiceProvider::class,
            // Inertia's ServiceProvider registers TestResponse::assertInertia
            // (TestResponseMacros mixin) — without it Feature tests on
            // Inertia-rendering endpoints can't introspect props. The
            // package is already in composer.json (statamic/cms pulls it),
            // we just need it booted in the Orchestra-Testbench env.
            \Inertia\ServiceProvider::class,
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
