<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Dashboard;

use Arturrossbach\Linkwise\Http\Controllers\DashboardController;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;
use ReflectionMethod;

/**
 * Pin the three-state semantics of
 * {@see DashboardController::staleCheckProps()}. This helper is spread into
 * **every** Linkwise Inertia page (8 renderers as of 2026-05-14:
 * Overview, Links, BrokenLinks, Domains, AutoLink, Keywords, Activity,
 * UrlChanger) so LinkwiseLayout can render the "broken-link check is
 * stale" banner regardless of which tab is active.
 *
 * Why a dedicated Unit-test before REV-DR-01 Phase B:
 * Phase B will split the renderers across sub-namespace controllers. A
 * naive split that copy-pastes `+ $this->staleCheckProps()` calls without
 * promoting the helper to a shared base/Trait/Service silently breaks 7 of
 * 8 pages — the banner would simply stop appearing. The matching Feature
 * tests in {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\
 * InertiaRendererStaleCheckTest} pin the *distribution* (every page emits
 * the keys). This test pins the *semantics* (the keys carry the right
 * values for the three input states).
 *
 * Three states modelled (grace window: 300 seconds):
 *  1. No index built yet               → is_stale = false (nothing to stale-check)
 *  2. Index built, no check ever run   → is_stale = true  (initial check missing)
 *  3a. Check newer than index          → is_stale = false (or within grace)
 *  3b. Check older than index by >300s → is_stale = true  (banner triggers)
 *
 * Once Phase B extracts the helper into a service the Reflection wrapper
 * drops and assertions become direct calls — test intent stays identical.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-DR-01
 */
class StaleCheckPropsTest extends TestCase
{
    private string $brokenLinksFile;

    /**
     * The helper hits {@see cp_route()} for `linkwise.check-links` and
     * `linkwise.check-links.status` — {@see \Statamic\Statamic::cpRoute()}
     * prepends `statamic.cp.`. Our routes/cp.php registers the unprefixed
     * variants only (Statamic::additionalCpRoutes doesn't boot in
     * Orchestra Testbench), so we side-load matching stubs scoped to this
     * suite. Same recipe as
     * {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\
     * ActivityDetailTest::defineRoutes()}.
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        $router->post('___test-stub/check-links', fn () => '')
            ->name('statamic.cp.linkwise.check-links');
        $router->get('___test-stub/check-links-status', fn () => '')
            ->name('statamic.cp.linkwise.check-links.status');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // BrokenLinkReport is `new`'d (not container-resolved) inside
        // staleCheckProps and reads from storage_path('linkwise')/
        // broken-links.json. We can't mock it without source-touch, so we
        // drive it through the filesystem: write a real JSON file with the
        // metadata.last_checked the test needs. Storage dir is per-test
        // because Orchestra Testbench points storage_path at a fresh
        // skeleton each suite.
        $dir = storage_path('linkwise');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->brokenLinksFile = $dir.'/broken-links.json';

        // Start clean — earlier tests may have left a file.
        if (file_exists($this->brokenLinksFile)) {
            unlink($this->brokenLinksFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->brokenLinksFile)) {
            unlink($this->brokenLinksFile);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_no_index_no_check_returns_not_stale_with_nulls(): void
    {
        // Fresh-install state. Index never built, broken-link check never
        // run. is_stale must be false — there's nothing to be stale
        // against, and showing the banner here would confuse a user who
        // hasn't even built the index yet.
        $this->bindIndexerWithLastBuiltAt(null);

        $props = $this->invoke();

        $this->assertFalse($props['staleCheck']['is_stale']);
        $this->assertNull($props['staleCheck']['index_built_at']);
        $this->assertNull($props['staleCheck']['broken_last_checked']);
    }

    public function test_index_present_but_no_check_yet_returns_stale(): void
    {
        // First-real-use state. The index has been built (user crawled
        // their site) but the broken-link checker hasn't run yet. The
        // banner must trigger to nudge the user toward the initial check.
        $this->bindIndexerWithLastBuiltAt('2026-05-14T10:00:00+00:00');

        $props = $this->invoke();

        $this->assertTrue($props['staleCheck']['is_stale']);
        $this->assertSame('2026-05-14T10:00:00+00:00', $props['staleCheck']['index_built_at']);
        $this->assertNull($props['staleCheck']['broken_last_checked']);
    }

    public function test_check_newer_than_index_returns_not_stale(): void
    {
        // Steady-state: user ran the broken-link check, then nothing
        // changed. Banner stays hidden until the next save bumps
        // index_built_at past last_checked + grace.
        $this->bindIndexerWithLastBuiltAt('2026-05-14T10:00:00+00:00');
        $this->writeBrokenLinksMetadata(['last_checked' => '2026-05-14T11:00:00+00:00']);

        $props = $this->invoke();

        $this->assertFalse($props['staleCheck']['is_stale']);
        $this->assertSame('2026-05-14T11:00:00+00:00', $props['staleCheck']['broken_last_checked']);
    }

    public function test_check_within_300s_grace_window_returns_not_stale(): void
    {
        // Grace-window guard. The comment in the helper calls out 300s
        // explicitly to avoid banner-flicker right after a save that
        // happens moments after a check completes. 250s offset stays
        // hidden; 301s offset triggers (see next test).
        $this->bindIndexerWithLastBuiltAt('2026-05-14T10:05:00+00:00');
        $this->writeBrokenLinksMetadata(['last_checked' => '2026-05-14T10:00:50+00:00']);
        // Index is 250s newer than check → within 300s grace → not stale.

        $props = $this->invoke();

        $this->assertFalse($props['staleCheck']['is_stale']);
    }

    public function test_check_older_than_index_by_more_than_300s_returns_stale(): void
    {
        // Editor-just-saved state. Index re-built > 5min after the last
        // check ran. Banner triggers — new edits may have introduced URLs
        // the check couldn't have seen.
        $this->bindIndexerWithLastBuiltAt('2026-05-14T10:10:00+00:00');
        $this->writeBrokenLinksMetadata(['last_checked' => '2026-05-14T10:00:00+00:00']);
        // 600s diff > 300s → stale.

        $props = $this->invoke();

        $this->assertTrue($props['staleCheck']['is_stale']);
    }

    public function test_props_always_carry_the_full_key_set(): void
    {
        // Phase B invariant guard: every render-call gets exactly these
        // five keys under staleCheck. LinkwiseLayout reads all of them;
        // any drop after the split silently breaks the banner / button
        // wiring. Renderer-distribution coverage (every page calls this
        // helper) lives in the Feature-test sibling.
        $this->bindIndexerWithLastBuiltAt(null);

        $props = $this->invoke();

        $this->assertArrayHasKey('staleCheck', $props);
        $this->assertSame([
            'is_stale',
            'index_built_at',
            'broken_last_checked',
            'check_url',
            'check_status_url',
        ], array_keys($props['staleCheck']));
        $this->assertIsString($props['staleCheck']['check_url']);
        $this->assertIsString($props['staleCheck']['check_status_url']);
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Invoke the protected helper via Reflection. Drops once Phase B
     * extracts the helper into a service with a public API.
     */
    private function invoke(): array
    {
        $controller = $this->app->make(DashboardController::class);

        $method = new ReflectionMethod($controller, 'staleCheckProps');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    /**
     * Override the container-bound {@see EntryIndexer} so the helper sees
     * exactly the timestamp this test cares about. Other indexer calls
     * are not expected on this code path and would fail the spy.
     */
    private function bindIndexerWithLastBuiltAt(?string $iso): void
    {
        $spy = Mockery::mock(EntryIndexer::class);
        $spy->shouldReceive('getIndexLastBuiltAt')->andReturn($iso);
        $this->app->instance(EntryIndexer::class, $spy);
    }

    /**
     * Write a real broken-links.json so the freshly-instantiated
     * {@see \Arturrossbach\Linkwise\Links\BrokenLinkReport} inside the
     * helper picks the metadata up. Avoids touching production source to
     * make the report mockable.
     */
    private function writeBrokenLinksMetadata(array $metadata): void
    {
        file_put_contents($this->brokenLinksFile, json_encode([
            'metadata' => $metadata,
            'broken_links' => [],
        ]));
    }
}
