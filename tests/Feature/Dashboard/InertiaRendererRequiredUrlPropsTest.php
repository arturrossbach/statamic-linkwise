<?php

namespace Arturrossbach\Linkwise\Tests\Feature\Dashboard;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;

/**
 * Pin **per-page required-URL-prop completeness** across all 8 Inertia
 * page renderers (Overview/Links/BrokenLinks/Domains/AutoLink/Keywords/
 * Activity/UrlChanger).
 *
 * ## Why this test exists
 *
 * User-Smoke 2026-05-17 (PR #56): Clicking "Scan Content" on the Activity
 * tab triggered "Could not scan content: HTTP 404". Root cause:
 * {@see \Arturrossbach\Linkwise\Http\Controllers\Dashboard\InertiaPagesController::activity}
 * forgot to pass the 3 rebuild-* URL props that all 6 sibling renderers
 * passed. `ActivityPage.vue` declares `rebuildUrl: { required: true }`
 * but Vue's `required: true` is a warn-only check — at runtime the
 * missing prop fell through to `undefined`, fetch() hit the empty URL,
 * the CP catch-all routed to a 404 page returning JSON-error.
 *
 * ## Bug class (Klasse 8 in `architectural_health.md`)
 *
 * For every Inertia page, every prop declared `required: true` in the
 * Vue file must be set non-empty by the corresponding renderer. Vue's
 * runtime check is warn-only, so PHPUnit is the only place we can fail
 * the build when an extracted/refactored renderer drops a prop.
 *
 * The bug surface only exists for **URL-string props** that the frontend
 * `fetch()`es — a missing required *data* prop (Object/Array) is a
 * legitimate empty-state in some Pages (cf. `brokenData`, `domains`)
 * and would be a false positive for `assertNotEmpty`. This pin
 * therefore covers URL-string `required:true` props only.
 *
 * ## What's pinned per page
 *
 * - `required_urls`: props declared `required: true` in the .vue. If
 *   the renderer doesn't set them OR sets them to '' → fail (the bug
 *   surface from #56). Source-of-truth grep used to seed the map:
 *   `grep -rEn "required:\s*true" resources/js/components/pages/`.
 * - `bundled_urls`: props that travel with `required_urls` as a
 *   semantic bundle even though Vue-declared as `default: ''`. We
 *   pin them non-empty too because PR #56 lost all three rebuild-*
 *   props together — the bundle is the unit-of-change in practice.
 *
 * Adding a new renderer or a new `required: true` URL prop: extend
 * `PAGE_PROPS` below. Removing a prop: drop the entry. The map is
 * deliberately explicit (not Vue-parsed) so a Vue-side edit doesn't
 * silently shift the contract.
 *
 * @see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\InertiaRendererStaleCheckTest
 *   Sister-distribution-pin for the `staleCheck` cross-cutting prop.
 */
class InertiaRendererRequiredUrlPropsTest extends TestCase
{
    /**
     * Page-route → expected URL props.
     *
     * `required_urls` mirrors Vue `required: true` declarations.
     * `bundled_urls` are non-required Vue props that ship together
     * with required ones and were the subject of PR #56's regression.
     */
    private const PAGE_PROPS = [
        'linkwise.dashboard' => [
            'component' => 'linkwise::Overview',
            'required_urls' => ['rebuildUrl'],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
        'linkwise.links' => [
            'component' => 'linkwise::Links',
            'required_urls' => ['rebuildUrl'],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
        'linkwise.broken' => [
            'component' => 'linkwise::BrokenLinks',
            'required_urls' => [
                'rebuildUrl',
                'checkLinksUrl',
                'checkLinksStatusUrl',
                'checkLinksCancelUrl',
            ],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
        'linkwise.domains' => [
            'component' => 'linkwise::Domains',
            'required_urls' => ['rebuildUrl', 'saveUrl'],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
        'linkwise.autolink' => [
            'component' => 'linkwise::AutoLink',
            'required_urls' => ['rebuildUrl'],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
        'linkwise.keywords' => [
            'component' => 'linkwise::Keywords',
            'required_urls' => ['rebuildUrl'],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
        'linkwise.activity' => [
            'component' => 'linkwise::Activity',
            'required_urls' => ['rebuildUrl', 'detailUrl'],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
        'linkwise.urlchanger' => [
            'component' => 'linkwise::UrlChanger',
            'required_urls' => ['rebuildUrl'],
            'bundled_urls' => ['rebuildStatusUrl', 'rebuildCancelUrl'],
        ],
    ];

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

    // ── Per-page tests ──────────────────────────────────────────────────
    // One test method per page so a failure names the failing page
    // directly in PHPUnit output instead of hiding behind a data-provider
    // row label.

    public function test_overview_renderer_carries_required_url_props(): void
    {
        $this->assertRendererCarriesUrlProps('linkwise.dashboard');
    }

    public function test_links_renderer_carries_required_url_props(): void
    {
        $this->assertRendererCarriesUrlProps('linkwise.links');
    }

    public function test_broken_renderer_carries_required_url_props(): void
    {
        $this->assertRendererCarriesUrlProps('linkwise.broken');
    }

    public function test_domains_renderer_carries_required_url_props(): void
    {
        $this->assertRendererCarriesUrlProps('linkwise.domains');
    }

    public function test_autolink_renderer_carries_required_url_props(): void
    {
        $this->assertRendererCarriesUrlProps('linkwise.autolink');
    }

    public function test_keywords_renderer_carries_required_url_props(): void
    {
        $this->assertRendererCarriesUrlProps('linkwise.keywords');
    }

    public function test_activity_renderer_carries_required_url_props(): void
    {
        // The PR #56 bug. Pre-fix: activity() in InertiaPagesController
        // didn't pass rebuildUrl/rebuildStatusUrl/rebuildCancelUrl.
        // All 6 sibling renderers did — activity was the outlier.
        // User-Smoke surfaced as "Could not scan content: HTTP 404".
        $this->assertRendererCarriesUrlProps('linkwise.activity');
    }

    public function test_url_changer_renderer_carries_required_url_props(): void
    {
        $this->assertRendererCarriesUrlProps('linkwise.urlchanger');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Single shared assertion: GET this URL → Inertia response →
     * component name matches + every required_url + bundled_url is
     * present and non-empty.
     *
     * Non-empty matters: an early version of activity() that just
     * filled `rebuildUrl => ''` would still satisfy "key present" but
     * leave the frontend fetching the empty URL — same 404. Pin both
     * shape AND non-emptiness.
     */
    private function assertRendererCarriesUrlProps(string $routeName): void
    {
        $config = self::PAGE_PROPS[$routeName];

        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '')
            ->get(route($routeName));

        $response->assertStatus(200);
        $this->assertSame($config['component'], $response->json('component'),
            "Renderer component mismatch for {$routeName}");

        $props = $response->json('props');

        foreach ($config['required_urls'] as $key) {
            $this->assertArrayHasKey($key, $props,
                "Renderer {$config['component']} missing required:true URL prop '{$key}' — "
                .'Vue declared it required, so the frontend will fall through to '
                .'an empty URL and any fetch() will 404 (cf. PR #56 Activity-Scan).');
            $this->assertNotEmpty($props[$key],
                "Renderer {$config['component']} has empty required:true URL prop '{$key}' — "
                .'same 404 surface as the missing-prop case.');
        }

        foreach ($config['bundled_urls'] as $key) {
            $this->assertArrayHasKey($key, $props,
                "Renderer {$config['component']} missing bundled URL prop '{$key}' — "
                .'declared default:"" in Vue but ships as a semantic bundle with '
                ."its required sibling; PR #56 lost all 3 rebuild-* props together.");
            $this->assertNotEmpty($props[$key],
                "Renderer {$config['component']} has empty bundled URL prop '{$key}' — "
                .'same bundle as the required sibling; would 404 the polling/cancel flow.');
        }
    }
}
