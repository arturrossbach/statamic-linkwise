<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkChecker;
use Arturrossbach\Linkwise\Links\BrokenLinkRecord;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Code-Review 2026-05-29 — Broken-link false positives.
 *
 * B-2 (checkUrl): the HEAD→GET retry only fired on 405/403, so HEAD-hostile
 *   servers that answer HEAD with 404/400/5xx (WAFs, CDNs, some nginx) but
 *   serve GET fine were flagged broken. HEAD is only a latency optimization;
 *   any non-OK HEAD must be confirmed with an authoritative GET.
 *
 * B-1 (containsTransientError): a transient network failure (timeout / refused
 *   connection) was cached for the full 24h TTL, pinning a working link as
 *   broken until the entry content changed. Such entries must be excluded from
 *   the scan cache so the next scan re-verifies them.
 */
class BrokenLinkCheckerFalsePositiveTest extends TestCase
{
    private function checker(): BrokenLinkChecker
    {
        // retries: 1 → one attempt per HTTP verb, so the Http::fake closure is
        // invoked exactly once for HEAD and once for the GET confirmation.
        return new BrokenLinkChecker(
            new EntryIndexer(sys_get_temp_dir().'/linkwise-bl-test-'.uniqid()),
            retries: 1,
        );
    }

    /** Fake HEAD and GET independently for the same URL. */
    private function fakeByMethod(int $headStatus, int $getStatus): void
    {
        Http::fake(function ($request) use ($headStatus, $getStatus) {
            $status = $request->method() === 'HEAD' ? $headStatus : $getStatus;

            return Http::response($status === 200 ? 'ok' : '', $status);
        });
    }

    // ── B-2: HEAD-hostile servers ──────────────────────────────────────

    public function test_head_ok_is_not_broken(): void
    {
        $this->fakeByMethod(headStatus: 200, getStatus: 200);

        $this->assertNull($this->checker()->checkUrl('https://example.test/page'));
    }

    public function test_head_404_but_get_200_is_not_broken(): void
    {
        // HEAD-hostile server: 404 on HEAD, 200 on GET. Must NOT be broken.
        $this->fakeByMethod(headStatus: 404, getStatus: 200);

        $this->assertNull(
            $this->checker()->checkUrl('https://head-hostile.test/page'),
            'a 404-on-HEAD / 200-on-GET server must be confirmed via GET, not flagged broken',
        );
    }

    public function test_head_500_but_get_200_is_not_broken(): void
    {
        $this->fakeByMethod(headStatus: 500, getStatus: 200);

        $this->assertNull($this->checker()->checkUrl('https://head-hostile.test/5xx'));
    }

    public function test_head_403_but_get_200_is_not_broken(): void
    {
        // Regression guard for the originally-handled 403 case.
        $this->fakeByMethod(headStatus: 403, getStatus: 200);

        $this->assertNull($this->checker()->checkUrl('https://head-hostile.test/403'));
    }

    public function test_genuinely_dead_url_404_on_both_is_broken(): void
    {
        // The fix must not hide real 404s: HEAD 404 + GET 404 stays broken.
        $this->fakeByMethod(headStatus: 404, getStatus: 404);

        $result = $this->checker()->checkUrl('https://dead.test/gone');

        $this->assertNotNull($result);
        $this->assertSame(404, $result['status_code']);
        $this->assertSame('not_found', $result['error_type']);
    }

    // ── B-1: transient errors are not cacheable ────────────────────────

    private function record(string $errorType): BrokenLinkRecord
    {
        return new BrokenLinkRecord(
            postId: 'p1',
            postTitle: 'P1',
            url: 'https://x.test/a',
            anchorText: 'a',
            type: 'external',
            statusCode: null,
            errorType: $errorType,
            firstDetectedAt: '2026-05-29T00:00:00+00:00',
            lastCheckedAt: '2026-05-29T00:00:00+00:00',
        );
    }

    private function containsTransient(array $records): bool
    {
        $m = new \ReflectionMethod(BrokenLinkChecker::class, 'containsTransientError');
        $m->setAccessible(true);

        return $m->invoke($this->checker(), $records);
    }

    public function test_timeout_record_marks_entry_as_transient(): void
    {
        $this->assertTrue($this->containsTransient([$this->record('timeout')]));
    }

    public function test_connection_failed_record_marks_entry_as_transient(): void
    {
        $this->assertTrue($this->containsTransient([$this->record('connection_failed')]));
    }

    public function test_authoritative_http_errors_are_not_transient(): void
    {
        // 404 / forbidden / server_error are authoritative — cacheable.
        $this->assertFalse($this->containsTransient([
            $this->record('not_found'),
            $this->record('forbidden'),
            $this->record('server_error'),
        ]));
    }

    public function test_empty_entry_is_not_transient(): void
    {
        $this->assertFalse($this->containsTransient([]));
    }

    public function test_mixed_set_with_one_transient_marks_whole_entry(): void
    {
        $this->assertTrue($this->containsTransient([
            $this->record('not_found'),
            $this->record('timeout'),
        ]));
    }
}
