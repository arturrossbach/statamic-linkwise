<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Dashboard;

use Arturrossbach\Linkwise\Http\Controllers\DashboardController;
use Arturrossbach\Linkwise\Tests\TestCase;
use ReflectionMethod;

/**
 * Pin the kind-switch in {@see DashboardController::deepLinkSearchFor()}.
 * Tested in isolation via Reflection — the helper is `protected` and the
 * Feature-test stack can't drive its non-null branches without tripping
 * the unprefixed-vs-statamic.cp routing quirk (cp_route would resolve
 * `statamic.cp.linkwise.urlchanger` which the test-routes file doesn't
 * register).
 *
 * Once REV-DR-01 Phase B extracts the helper into a service the
 * Reflection wrapper drops and assertions become direct calls — the test
 * intent stays identical.
 *
 * Bug-anchor: commit 6839eb8 (Activity Log: per-item operation data +
 * URL Changer deep-link) — Phase B may move this logic, drift risk on
 * the kind-switch is the reason this exists.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-DR-01
 */
class ActivityDeepLinkSearchTest extends TestCase
{
    public function test_returns_first_item_url_for_single_rule_applyrule(): void
    {
        $result = $this->invoke([
            'kind' => 'applyrule',
            'items' => [
                ['url' => 'https://example.com/post-a', 'anchor' => 'foo'],
                ['url' => 'https://example.com/post-b', 'anchor' => 'bar'],
            ],
            'summary' => ['mode' => 'single-rule'],
        ]);

        // Single-rule applyrule: all items share one URL, take the first.
        // Multi-URL collisions can't happen by definition of single-rule.
        $this->assertSame('https://example.com/post-a', $result);
    }

    public function test_returns_null_for_multi_rule_applyrule(): void
    {
        $result = $this->invoke([
            'kind' => 'applyrule',
            'items' => [
                ['url' => 'https://example.com/rule-1-url', 'anchor' => 'foo'],
            ],
            'summary' => ['mode' => 'multi-rule'],
        ]);

        // Multi-rule applies many different URLs in one batch — no single
        // URL Changer search term makes sense. Drawer hides the deep-link
        // button.
        $this->assertNull($result);
    }

    public function test_returns_null_for_applyrule_without_items(): void
    {
        // Empty-affected-entries / orphan-snapshot case (REV-AL-01
        // documented bug-smell). `$first` is null, the `is_array` guard
        // catches it.
        $result = $this->invoke([
            'kind' => 'applyrule',
            'items' => [],
            'summary' => ['mode' => 'single-rule'],
        ]);

        $this->assertNull($result);
    }

    public function test_returns_summary_search_for_urlchanger_kind(): void
    {
        // urlchanger snapshots persist the user's search term in
        // summary.search — the drawer round-trips it back into the URL
        // Changer search box. Empty / missing search → null (falsy-check
        // in controller via ?? null).
        $result = $this->invoke([
            'kind' => 'urlchanger',
            'items' => [],
            'summary' => ['search' => '/old-blog?id=42'],
        ]);

        $this->assertSame('/old-blog?id=42', $result);
    }

    public function test_returns_null_for_urlchanger_without_search(): void
    {
        $result = $this->invoke([
            'kind' => 'urlchanger',
            'items' => [],
            'summary' => [],
        ]);

        $this->assertNull($result);
    }

    public function test_returns_null_for_inbound_and_outbound_insert_kinds(): void
    {
        // Comment in controller: "target entry is the same across all
        // items in inbound mode; for outbound the source is shared.
        // Either way, no single URL." — pin the explicit branch.
        foreach (['inboundinsert', 'outboundinsert'] as $kind) {
            $result = $this->invoke([
                'kind' => $kind,
                'items' => [['url' => 'https://example.com/x']],
                'summary' => [],
            ]);
            $this->assertNull($result, "Expected null for kind={$kind}");
        }
    }

    public function test_returns_null_for_unknown_kind(): void
    {
        // Default branch — future kinds added without a deep-link rule
        // fall through to null rather than crashing or guessing.
        $result = $this->invoke([
            'kind' => 'some-future-kind',
            'items' => [['url' => 'https://example.com/x']],
            'summary' => ['search' => 'ignored-for-non-urlchanger'],
        ]);

        $this->assertNull($result);
    }

    public function test_handles_missing_kind_and_items_keys_defensively(): void
    {
        // Legacy / corrupted snapshots may lack the keys entirely.
        // `?? ''` / `?? []` / `?? null` guards in the controller turn
        // them into a default-branch fall-through.
        $result = $this->invoke([]);

        $this->assertNull($result);
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Invoke the protected helper via Reflection. Cleared once Phase B
     * extracts deepLinkSearchFor into a service with a public API.
     */
    private function invoke(array $snapshot): ?string
    {
        $controller = $this->app->make(DashboardController::class);

        $method = new ReflectionMethod($controller, 'deepLinkSearchFor');
        $method->setAccessible(true);

        return $method->invoke($controller, $snapshot);
    }
}
