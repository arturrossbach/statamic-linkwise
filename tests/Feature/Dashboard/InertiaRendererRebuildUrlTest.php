<?php

namespace Arturrossbach\Linkwise\Tests\Feature\Dashboard;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;

/**
 * Pin the **distribution** of `rebuildUrl` + `rebuildStatusUrl` +
 * `rebuildCancelUrl` props across all 7 Inertia renderers
 * (Overview/Links/BrokenLinks/Domains/AutoLink/Keywords/Activity/UrlChanger).
 *
 * User-Smoke 2026-05-17: Clicking "Scan Content" on the Activity tab
 * triggered "Could not scan content: HTTP 404". Root cause:
 * {@see \Arturrossbach\Linkwise\Http\Controllers\Dashboard\InertiaPagesController::activity}
 * forgot to pass the 3 rebuild-* URL props that all 6 sibling renderers
 * pass. `ActivityPage.vue` declares `rebuildUrl: { required: true }` but
 * Vue prop validation is a warn-only check — at runtime the missing prop
 * fell through to `undefined`, fetch() hit the empty URL, the CP catch-
 * all routed to a 404 page returning JSON-error.
 *
 * Same anti-pattern class as {@see InertiaRendererStaleCheckTest}: the
 * cross-cutting URL props get distributed across 7 renderers via copy-
 * paste, one renderer drops it during a refactor, the user only finds
 * out via the in-browser smoke. Sister-distribution-pin catches the
 * gap structurally — adding a NEW renderer now requires either
 * carrying the props OR explicitly opting out (with a code-comment).
 *
 * Test stack reused from InertiaRendererStaleCheckTest (same defineRoutes
 * + Mockery'd Indexer + X-Inertia header trick).
 */
class InertiaRendererRebuildUrlTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        // Pattern from InertiaRendererStaleCheckTest: any() stubs under
        // `statamic.cp.linkwise.*` so cp_route() resolves in-test the
        // same way production does. We never *call* these stubs — they
        // exist only so URL generation succeeds inside renderer props.
        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'linkwise.')) {
                $stubPath = '___test-stub/'.str_replace('.', '-', $name);
                $router->any($stubPath, fn () => '')
                    ->name('statamic.cp.'.$name);
            }
        }

        // Statamic registers collections.entries.edit for edit URLs in
        // renderer props; tests don't.
        $router->get('___test-stub/collections-entries-edit/{collection}/{id}', fn () => '')
            ->name('collections.entries.edit');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        // Empty-indexer trick: LinkReport / DomainReport iterate
        // entries → with load()=[] everything no-ops cleanly without
        // a Stache.
        $indexer = Mockery::mock(EntryIndexer::class);
        $indexer->shouldReceive('load')->andReturn([]);
        $indexer->shouldReceive('getIndexLastBuiltAt')->andReturn(null);
        $indexer->shouldReceive('getIndexAge')->andReturn(null);
        $this->app->instance(EntryIndexer::class, $indexer);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_overview_renderer_carries_rebuild_url_trio(): void
    {
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.dashboard'),
            component: 'linkwise::Overview',
        );
    }

    public function test_links_renderer_carries_rebuild_url_trio(): void
    {
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.links'),
            component: 'linkwise::Links',
        );
    }

    public function test_broken_renderer_carries_rebuild_url_trio(): void
    {
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.broken'),
            component: 'linkwise::BrokenLinks',
        );
    }

    public function test_domains_renderer_carries_rebuild_url_trio(): void
    {
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.domains'),
            component: 'linkwise::Domains',
        );
    }

    public function test_autolink_renderer_carries_rebuild_url_trio(): void
    {
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.autolink'),
            component: 'linkwise::AutoLink',
        );
    }

    public function test_keywords_renderer_carries_rebuild_url_trio(): void
    {
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.keywords'),
            component: 'linkwise::Keywords',
        );
    }

    public function test_activity_renderer_carries_rebuild_url_trio(): void
    {
        // The bug. Pre-fix: activity() block in InertiaPagesController
        // doesn't pass rebuildUrl/rebuildStatusUrl/rebuildCancelUrl
        // (lines 462-…). All 6 sibling renderers do — activity is the
        // outlier. User-Smoke 2026-05-17 surfaced as
        // "Could not scan content: HTTP 404" toast.
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.activity'),
            component: 'linkwise::Activity',
        );
    }

    public function test_url_changer_renderer_carries_rebuild_url_trio(): void
    {
        $this->assertRendererCarriesRebuildUrls(
            route: route('linkwise.urlchanger'),
            component: 'linkwise::UrlChanger',
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Single shared assertion: GET this URL → Inertia response →
     * component name matches + all 3 rebuild-URL props are present
     * and non-empty.
     *
     * Non-empty matters: an early version of activity() that just
     * filled `rebuildUrl => ''` would still satisfy "key present" but
     * leave the frontend fetching the empty URL — same 404. Pin both
     * shape AND non-emptiness.
     */
    private function assertRendererCarriesRebuildUrls(string $route, string $component): void
    {
        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '')
            ->get($route);

        $response->assertStatus(200);
        $this->assertSame($component, $response->json('component'),
            "Renderer component mismatch for {$route}");

        $props = $response->json('props');

        foreach (['rebuildUrl', 'rebuildStatusUrl', 'rebuildCancelUrl'] as $key) {
            $this->assertArrayHasKey($key, $props,
                "Renderer {$component} missing prop {$key} — frontend's "
                .'LinkwiseLayout will fall through to an empty URL and '
                .'the Scan-Content button will 404 (User-Smoke 2026-05-17).');
            $this->assertNotEmpty($props[$key],
                "Renderer {$component} has empty {$key} — same 404 surface "
                .'as the missing-prop case.');
        }
    }
}
