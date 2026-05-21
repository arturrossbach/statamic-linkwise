<?php

namespace Arturrossbach\Linkwise\Tests\Feature\Dashboard;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;

/**
 * Pin the **distribution** of {@see
 * \Arturrossbach\Linkwise\Http\Controllers\DashboardController::
 * staleCheckProps()} across all 8 Inertia renderers of the Dashboard:
 * Overview, Links, BrokenLinks, Domains, AutoLink, Keywords, Activity,
 * UrlChanger. Sibling
 * {@see \Arturrossbach\Linkwise\Tests\Unit\Dashboard\StaleCheckPropsTest}
 * pins the helper's semantics — this one pins that every renderer
 * actually carries the keys forward into its Inertia response.
 *
 * Why both: REV-DR-01 Phase B will split the renderers across
 * sub-namespace controllers (`Http/Controllers/Dashboard/`). A naive split
 * that copies `+ $this->staleCheckProps()` calls without promoting the
 * helper to a shared base/Trait/Service would silently break 7 of 8
 * pages — the stale-check banner would only appear on whichever
 * controller still owns the helper. The semantics pin catches changes to
 * the keys themselves; this distribution pin catches dropped calls.
 *
 * Test stack reused from REV-AL-01 (feature_test_stack memory):
 * - `defineRoutes` side-loads `routes/cp.php` (via TestCase).
 * - We additionally alias every `linkwise.*` route as `statamic.cp.
 *   linkwise.*` because the renderers call {@see cp_route()} liberally
 *   and Statamic's CP-prefix boot doesn't run in Orchestra Testbench.
 * - Empty-Indexer trick: `EntryIndexer::load()` mocked to `[]` →
 *   LinkReport / DomainReport / TextExtractor all no-op cleanly, no
 *   Stache fixtures needed.
 * - {@see \Arturrossbach\Linkwise\Links\BrokenLinkReport} is `new`'d
 *   inside the helper; with no broken-links.json on disk it reads as
 *   metadata=null, which is exactly the "no check yet" state our
 *   not_stale assertion needs to be deterministic.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-DR-01
 */
class InertiaRendererStaleCheckTest extends TestCase
{
    private string $brokenLinksFile;

    /**
     * Add `statamic.cp.linkwise.*` aliases for every unprefixed
     * `linkwise.*` route the renderers might call. Cheaper than
     * enumerating per-renderer: every renderer's `cp_route` calls
     * resolve, none surface as RouteNotFoundException, the assertion
     * focus stays on staleCheckProps presence.
     *
     * Also alias `collections.entries.edit` which the renderers call to
     * build entry edit-URLs (real Statamic registers it; Orchestra
     * Testbench does not).
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        // Alias every registered `linkwise.*` route under `statamic.cp.
        // linkwise.*` so cp_route() resolves it the way production does.
        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'linkwise.')) {
                $stubPath = '___test-stub/'.str_replace('.', '-', $name);
                // Use any() so a route used as POST in production but
                // built as a URL in a renderer's props doesn't trip a
                // verb mismatch — we never *call* these stubs.
                $router->any($stubPath, fn () => '')
                    ->name('statamic.cp.'.$name);
            }
        }

        // Statamic core registers collections.entries.edit; tests don't.
        $router->get('___test-stub/collections-entries-edit/{collection}/{id}', fn () => '')
            ->name('collections.entries.edit');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $dir = storage_path('linkwise');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->brokenLinksFile = $dir.'/broken-links.json';

        // Ensure a deterministic "no check yet" state: is_stale = false
        // when index_built_at is also null (we run with empty indexer).
        // Any leftover file from another test would flip is_stale.
        if (file_exists($this->brokenLinksFile)) {
            unlink($this->brokenLinksFile);
        }

        $this->stubEmptyIndexer();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->brokenLinksFile)) {
            unlink($this->brokenLinksFile);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_overview_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.dashboard'),
            component: 'linkwise::Overview',
        );
    }

    public function test_links_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.links'),
            component: 'linkwise::Links',
        );
    }

    public function test_broken_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.broken'),
            component: 'linkwise::BrokenLinks',
        );
    }

    public function test_domains_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.domains'),
            component: 'linkwise::Domains',
        );
    }

    public function test_autolink_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.autolink'),
            component: 'linkwise::AutoLink',
        );
    }

    public function test_keywords_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.keywords'),
            component: 'linkwise::Keywords',
        );
    }

    public function test_activity_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.activity'),
            component: 'linkwise::Activity',
        );
    }

    public function test_url_changer_renderer_carries_stale_check_props(): void
    {
        $this->assertRendererCarriesStaleCheck(
            route: route('linkwise.urlchanger'),
            component: 'linkwise::UrlChanger',
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Single shared assertion. Each renderer test reduces to "GET this
     * URL → Inertia response → component name matches + staleCheck
     * sub-tree present with the 5 documented keys". The 5-key shape
     * itself is pinned in StaleCheckPropsTest; here we only assert that
     * the renderer forwards the helper's output unchanged.
     */
    private function assertRendererCarriesStaleCheck(string $route, string $component): void
    {
        // Use the X-Inertia request mode to bypass the HTML shell.
        // Statamic's CpServiceProvider sets the Inertia root view to
        // `statamic::layout`, which pulls in a Vite manifest that doesn't
        // exist in Orchestra Testbench. With the X-Inertia header Inertia
        // returns a JsonResponse instead, carrying the same `page`
        // payload — we drive assertions through `$response->json(...)`
        // directly. AssertableInertia would have required the HTML path.
        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '')
            ->get($route);

        $response->assertStatus(200);
        $response->assertHeader('X-Inertia', 'true');
        $this->assertSame($component, $response->json('component'),
            "Renderer component mismatch for {$route}");

        // The 5-key shape itself is pinned in StaleCheckPropsTest; here
        // we only assert presence (distribution) — the props must travel
        // from the helper into the Inertia response intact.
        $this->assertArrayHasKey('staleCheck', $response->json('props'));
        $this->assertArrayHasKey('is_stale', $response->json('props.staleCheck'));
        $this->assertArrayHasKey('index_built_at', $response->json('props.staleCheck'));
        $this->assertArrayHasKey('broken_last_checked', $response->json('props.staleCheck'));
        $this->assertArrayHasKey('check_url', $response->json('props.staleCheck'));
        $this->assertArrayHasKey('check_status_url', $response->json('props.staleCheck'));

        // Empty-indexer + missing broken-links.json → not stale, both
        // timestamps null. Pinning values too (not just presence) catches
        // the worst Phase-B regression: a sub-controller returning a
        // hard-coded shape with all five keys but wrong content.
        $this->assertFalse($response->json('props.staleCheck.is_stale'));
        $this->assertNull($response->json('props.staleCheck.index_built_at'));
        $this->assertNull($response->json('props.staleCheck.broken_last_checked'));
    }

    /**
     * Empty-Indexer trick from feature_test_stack memory. With load() →
     * [] LinkReport/DomainReport/TextExtractor all no-op without booting
     * Stache. The container alias also ensures the controller's
     * constructor-injected indexer matches the one used by `new
     * DomainReport($this->indexer)` deeper in the renderer.
     */
    private function stubEmptyIndexer(): void
    {
        $spy = Mockery::mock(EntryIndexer::class);
        $spy->shouldReceive('load')->andReturn([]);
        $spy->shouldReceive('save')->andReturnNull();
        $spy->shouldReceive('getIndexLastBuiltAt')->andReturn(null);
        // DomainReport and others may call additional methods on a real
        // indexer. With an empty load() result the renderers don't reach
        // those paths, so we leave the spy strict — any unexpected call
        // will surface as a clear "no expectation defined" error during
        // green-loop dev.
        $this->app->instance(EntryIndexer::class, $spy);
    }
}
