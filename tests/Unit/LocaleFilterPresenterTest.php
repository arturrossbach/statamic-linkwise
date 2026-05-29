<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Support\LocaleFilterPresenter;
use Arturrossbach\Linkwise\Tests\TestCase;
use Illuminate\Http\Request;

/**
 * V1.2 Cross-Tab-A pins. LocaleFilterPresenter is the single source of
 * truth every multilang-aware tab uses to derive its filter props. The
 * tests cover:
 *
 * - Locales come from the INDEX, not from sites.yaml (a configured site
 *   with zero indexed content shouldn't appear in the dropdown).
 * - Single-locale indices produce empty `availableLocales` (frontend
 *   hides the widget). Threshold is exactly 2 distinct locales.
 * - `?locale=` filter rejects unknown values silently — a user pasting
 *   `?locale=fr` on an index that has no FR content must not 500.
 * - Filter applies record-by-record, doesn't mutate the input.
 */
class LocaleFilterPresenterTest extends TestCase
{
    private function record(string $id, ?string $locale): EntryRecord
    {
        return new EntryRecord(
            id: $id,
            title: $id,
            url: '/'.$id,
            collection: 'articles',
            text: '',
            outboundLinks: [],
            locale: $locale,
        );
    }

    private function request(?string $locale): Request
    {
        return $locale === null
            ? Request::create('/cp/linkwise/links', 'GET')
            : Request::create('/cp/linkwise/links?locale='.$locale, 'GET');
    }

    public function test_available_locales_lists_distinct_indexed_locales_sorted(): void
    {
        $records = [
            'a' => $this->record('a', 'nl'),
            'b' => $this->record('b', 'de'),
            'c' => $this->record('c', 'en'),
            'd' => $this->record('d', 'de'),
        ];

        $this->assertSame(
            ['de', 'en', 'nl'],
            LocaleFilterPresenter::availableLocales($records)
        );
    }

    public function test_available_locales_returns_empty_when_only_one_distinct_locale(): void
    {
        // Single-locale index = hide the filter. Editor on a single-site
        // install or a multisite where only one site has content sees no
        // filter widget — cleaner than a Site::multiEnabled-only check.
        $records = [
            'a' => $this->record('a', 'en'),
            'b' => $this->record('b', 'en'),
        ];

        $this->assertSame([], LocaleFilterPresenter::availableLocales($records));
    }

    public function test_available_locales_ignores_null_locales(): void
    {
        $records = [
            'a' => $this->record('a', 'de'),
            'b' => $this->record('b', null), // legacy / pre-PR-#101 record
            'c' => $this->record('c', 'en'),
        ];

        $this->assertSame(['de', 'en'], LocaleFilterPresenter::availableLocales($records));
    }

    public function test_apply_returns_unfiltered_records_when_no_locale_in_request(): void
    {
        $records = [
            'a' => $this->record('a', 'de'),
            'b' => $this->record('b', 'en'),
        ];

        $result = LocaleFilterPresenter::apply($records, $this->request(null));

        $this->assertNull($result['activeLocale']);
        $this->assertCount(2, $result['filteredRecords']);
    }

    public function test_apply_filters_records_to_matching_locale(): void
    {
        $records = [
            'a' => $this->record('a', 'de'),
            'b' => $this->record('b', 'en'),
            'c' => $this->record('c', 'de'),
        ];

        $result = LocaleFilterPresenter::apply($records, $this->request('de'));

        $this->assertSame('de', $result['activeLocale']);
        $this->assertCount(2, $result['filteredRecords']);
        $this->assertArrayHasKey('a', $result['filteredRecords']);
        $this->assertArrayHasKey('c', $result['filteredRecords']);
    }

    public function test_apply_ignores_unknown_locale_silently(): void
    {
        // User pastes ?locale=fr but the index has no FR content. Don't
        // 500, don't filter, just return the unfiltered set with
        // activeLocale=null so the frontend renders "All languages."
        $records = [
            'a' => $this->record('a', 'de'),
            'b' => $this->record('b', 'en'),
        ];

        $result = LocaleFilterPresenter::apply($records, $this->request('fr'));

        $this->assertNull($result['activeLocale']);
        $this->assertCount(2, $result['filteredRecords']);
    }

    public function test_apply_returns_empty_filter_state_on_single_locale_index(): void
    {
        $records = [
            'a' => $this->record('a', 'en'),
            'b' => $this->record('b', 'en'),
        ];

        $result = LocaleFilterPresenter::apply($records, $this->request('en'));

        // Even with `?locale=en` in the request, single-locale index
        // means availableLocales is empty AND activeLocale should be
        // null — the filter widget wouldn't render, so the URL param
        // is effectively dead. Filter passes everything through.
        $this->assertSame([], $result['availableLocales']);
        $this->assertNull($result['activeLocale']);
        $this->assertCount(2, $result['filteredRecords']);
    }

    // ── O-4: breakdown() — Overview headline-stats chips ──────────────

    public function test_breakdown_returns_per_locale_counts_sorted(): void
    {
        $records = [
            'a' => $this->record('a', 'en'),
            'b' => $this->record('b', 'de'),
            'c' => $this->record('c', 'en'),
            'd' => $this->record('d', 'en'),
            'e' => $this->record('e', 'de'),
        ];

        $this->assertSame(
            ['de' => 2, 'en' => 3],
            LocaleFilterPresenter::breakdown($records),
        );
    }

    public function test_breakdown_is_empty_on_single_locale_index(): void
    {
        // < 2 distinct locales → chips don't render (single-site stays
        // visually identical to pre-V1.2).
        $records = [
            'a' => $this->record('a', 'en'),
            'b' => $this->record('b', 'en'),
        ];

        $this->assertSame([], LocaleFilterPresenter::breakdown($records));
    }

    public function test_breakdown_is_empty_for_empty_index(): void
    {
        $this->assertSame([], LocaleFilterPresenter::breakdown([]));
    }

    public function test_breakdown_ignores_null_locale_records(): void
    {
        // Legacy records without a locale stamp must not become a phantom
        // bucket.
        $records = [
            'a' => $this->record('a', 'en'),
            'b' => $this->record('b', 'de'),
            'c' => $this->record('c', null),
            'd' => $this->record('d', 'de'),
        ];

        $this->assertSame(['de' => 2, 'en' => 1], LocaleFilterPresenter::breakdown($records));
    }
}
