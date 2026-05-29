<?php

namespace Arturrossbach\Linkwise\Support;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Illuminate\Http\Request;
use Statamic\Facades\Site;

/**
 * Builds the locale-filter props every multilang-aware tab pushes into
 * its Inertia render. Centralizes:
 *
 *  - `availableLocales`: the distinct locale stamps present in the
 *    persisted index. NOT `Site::all()` — a site can be configured in
 *    sites.yaml but have zero indexed entries (collection not enabled
 *    there, or empty content tree). Filter dropdown only lists locales
 *    that actually have content.
 *  - `activeLocale`: the current filter value, read from `?locale=` so
 *    the URL is the source of truth (refresh-survivable, shareable,
 *    browser-back-button-friendly). Null when no filter is active.
 *
 * Single-locale-content installs (zero or one distinct locale in the
 * index — typical of single-site Statamic OR a multisite where only one
 * site has been seeded) get `availableLocales = []`; the frontend hides
 * the filter widget entirely. Cleaner than a `Site::multiEnabled()`-only
 * check, which would still surface the dropdown on multisite-but-only-
 * one-locale-has-content edge cases.
 *
 * Filter naivety: this presenter reads what's in the index. If a user
 * has `linkwise.collections` scoped to a collection that lives only on
 * one site, the other sites' locales won't appear here even though they
 * exist in sites.yaml. That's correct behavior — filter reflects
 * indexable content, not site-configuration. Worth noting if a reviewer
 * asks.
 */
class LocaleFilterPresenter
{
    /**
     * Apply the request's `?locale=` filter to the record set + build the
     * Inertia props the frontend needs to render the LocaleFilter widget.
     *
     * @param  array<string, EntryRecord>  $records  Full record set from
     *   the indexer. The filtered subset is what the controller should
     *   pass to LinkReport / its tab-specific logic.
     * @return array{
     *     filteredRecords: array<string, EntryRecord>,
     *     availableLocales: list<string>,
     *     activeLocale: ?string,
     * }
     */
    public static function apply(array $records, Request $request): array
    {
        $availableLocales = self::availableLocales($records);
        $requested = $request->query('locale');
        $activeLocale = (is_string($requested) && in_array($requested, $availableLocales, true))
            ? $requested
            : null;

        $filtered = $activeLocale === null
            ? $records
            : array_filter($records, fn (EntryRecord $r) => $r->locale === $activeLocale);

        return [
            'filteredRecords' => $filtered,
            'availableLocales' => $availableLocales,
            'activeLocale' => $activeLocale,
        ];
    }

    /**
     * Distinct, sorted, non-null locales present in the index. Returns
     * an empty list when the index has fewer than 2 distinct locales —
     * single-locale installs don't need a filter widget at all.
     *
     * @param  array<string, EntryRecord>  $records
     * @return list<string>
     */
    public static function availableLocales(array $records): array
    {
        $locales = [];
        foreach ($records as $r) {
            if ($r->locale !== null && ! isset($locales[$r->locale])) {
                $locales[$r->locale] = true;
            }
        }
        if (count($locales) < 2) {
            return [];
        }
        $list = array_keys($locales);
        sort($list);
        return $list;
    }

    /**
     * Per-locale entry counts for the Overview's headline-stats chips
     * (e.g. ['de' => 10, 'en' => 165]), sorted by locale code. Returns an
     * empty array when fewer than 2 distinct locales are present — single-
     * locale installs don't render the chips, keeping the card visually
     * identical to pre-V1.2.
     *
     * O-4 (2026-05-29): MUST be computed on the FULL record set — it is an
     * install-wide nav aid. Scoping it to a locale-filtered subset would
     * collapse it to the one selected locale (< 2 → empty) and make the
     * chips vanish exactly when a multilingual editor is using them.
     * Extracted from the inline loop in InertiaPagesController::index so it
     * has a unit-test seam.
     *
     * @param  array<string, EntryRecord>  $records
     * @return array<string, int>
     */
    public static function breakdown(array $records): array
    {
        $counts = [];
        foreach ($records as $r) {
            if ($r->locale === null) {
                continue;
            }
            $counts[$r->locale] = ($counts[$r->locale] ?? 0) + 1;
        }
        if (count($counts) < 2) {
            return [];
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Sites-based multilang detection — independent of any index state.
     * Returns true when sites.yaml declares ≥2 distinct `lang:` values.
     *
     * Distinct from availableLocales() which is INDEX-based: this one
     * reflects the static install configuration regardless of whether
     * the index has been built yet, which matters for any UI surface
     * that must render correctly on a fresh-install-pre-first-scan.
     * Currently used to hide the "Single-site content language"
     * settings field on multilingual installs where it does not apply.
     *
     * Same-lang multisite (e.g. 5 EN-only domains for geo/SEO) returns
     * false — only one distinct language, so the global content-
     * language field is still relevant.
     *
     * Fail-safe: any exception (Site facade not bootable, malformed
     * sites.yaml) returns false, which keeps the field visible.
     * Visible-but-irrelevant is better UX than missing-and-confusing.
     */
    public static function isMultilingualBySites(): bool
    {
        try {
            $distinctLangs = Site::all()
                ->map(fn ($site) => $site->lang())
                ->filter() // strip null/empty
                ->unique()
                ->count();

            return $distinctLangs >= 2;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Strip the "Single-site content language" field from the Linkwise
     * settings blueprint array IF the install is multilingual-by-sites.
     * Pure function — no side effects, easily testable.
     *
     * Called from ServiceProvider::bootAddon() inside the container's
     * settings_blueprint binding-override closure. Extracted as static
     * so we can pin the filter logic without going through Statamic's
     * Addon-binding plumbing (which doesn't exist in Orchestra Testbench).
     */
    public static function stripLanguageFieldIfMultilingual(array $blueprint): array
    {
        if (! self::isMultilingualBySites()) {
            return $blueprint;
        }

        if (isset($blueprint['sections']['general']['fields']) && is_array($blueprint['sections']['general']['fields'])) {
            $blueprint['sections']['general']['fields'] = array_values(array_filter(
                $blueprint['sections']['general']['fields'],
                fn ($f) => ($f['handle'] ?? null) !== 'language',
            ));
        }

        return $blueprint;
    }

    /**
     * Inertia-prop spread helper. Convenience for controllers that just
     * want to dump filter props alongside their domain props.
     *
     * @param  array<string, EntryRecord>  $records
     * @return array{availableLocales: list<string>, activeLocale: ?string}
     */
    public static function buildProps(array $records, Request $request): array
    {
        $availableLocales = self::availableLocales($records);
        $requested = $request->query('locale');
        $activeLocale = (is_string($requested) && in_array($requested, $availableLocales, true))
            ? $requested
            : null;

        return [
            'availableLocales' => $availableLocales,
            'activeLocale' => $activeLocale,
        ];
    }
}
