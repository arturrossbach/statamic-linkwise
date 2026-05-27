<?php

namespace Arturrossbach\Linkwise\Support;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;

/**
 * Builds the cross-tab "broken-link check is stale" banner Inertia props.
 *
 * Extracted from {@see \Arturrossbach\Linkwise\Http\Controllers\DashboardController::staleCheckProps()}
 * during REV-DR-01 Phase B PR 2.
 *
 * Spread into every Linkwise page's Inertia props (8 renderers: Overview,
 * Links, BrokenLinks, Domains, AutoLink, Keywords, Activity, UrlChanger) so
 * {@link LinkwiseLayout.vue} can render the "stale-check" banner regardless
 * of which tab is active. PR 5 will split those renderers into sub-controllers;
 * a stateless static helper avoids 8× DI plumbing for varying constructor
 * dep-sets (advisor recommendation — service over Trait/Base-Class).
 *
 * Three states modelled (grace window: 300 seconds):
 *  1. No index built yet               → is_stale = false
 *  2. Index built, no check ever run   → is_stale = true (initial check missing)
 *  3a. Check newer than index          → is_stale = false (or within grace)
 *  3b. Check older than index by >300s → is_stale = true (banner triggers)
 *
 * Behaviour pinned by {@see \Arturrossbach\Linkwise\Tests\Unit\Dashboard\StaleCheckPropsTest}
 * (semantics) and {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\InertiaRendererStaleCheckTest}
 * (distribution across all 8 renderers).
 */
class StaleCheckPresenter
{
    public static function buildProps(EntryIndexer $indexer): array
    {
        $indexAt = $indexer->getIndexLastBuiltAt();
        $brokenReport = new BrokenLinkReport;
        $brokenLastChecked = $brokenReport->load()['metadata']['last_checked'] ?? null;

        // 5-minute grace window so a check that ran moments after a save
        // doesn't flicker the banner. Editors rarely care about stale-check
        // signals at sub-minute granularity.
        $isStale = false;
        if ($indexAt) {
            if (! $brokenLastChecked) {
                $isStale = true; // initial check never run
            } else {
                $isStale = strtotime($indexAt) > strtotime($brokenLastChecked) + 300;
            }
        }

        // Exec-availability check distributed alongside the stale-banner
        // payload — both are cross-cutting "show on every Linkwise page"
        // signals, so they share the distribution mechanism.
        //
        // Why this lives here: LinkwiseLayout renders the warning banner
        // when exec() / proc_open() are disabled (typical shared-hosting
        // restriction). Without the check, a user clicks Scan Content,
        // BulkJobDispatcher's exec() silently returns false, and the job
        // sits in "starting" forever. The banner makes the constraint
        // visible up-front instead of letting users hit the dead end.
        $execCheck = ExecAvailability::check();

        return [
            'staleCheck' => [
                'is_stale' => $isStale,
                'index_built_at' => $indexAt,
                'broken_last_checked' => $brokenLastChecked,
                'check_url' => cp_route('linkwise.check-links'),
                'check_status_url' => cp_route('linkwise.check-links.status'),
            ],
            // Real installed version for the Help-dropdown + support-email
            // body. Sourced from Statamic's Addon registry (which reads
            // Composer's installed metadata), NOT hardcoded — production
            // returns the tag ("1.2.1"), local path-repo dev returns
            // "dev-master". Null when the addon can't be resolved (defensive);
            // the frontend then hides the version line entirely rather than
            // showing a stale/fantasy number.
            'linkwiseVersion' => self::resolveVersion(),
            'execAvailability' => [
                'available' => $execCheck['exec_available'] && $execCheck['proc_open_available'],
                'exec_available' => $execCheck['exec_available'],
                'proc_open_available' => $execCheck['proc_open_available'],
                // Echo the disable_functions list so the banner can name
                // the specific restriction in its hosting-support copy.
                'disabled_functions' => array_values(array_filter(
                    $execCheck['disabled_functions'],
                    fn ($f) => in_array(strtolower((string) $f), ['exec', 'proc_open', 'shell_exec', 'popen', 'system', 'passthru'], true),
                )),
            ],
        ];
    }

    /**
     * Resolve the installed addon version via Statamic's Addon registry.
     *
     * The marketplace id strips the `statamic-` prefix from the composer
     * package name, so the registry key is `arturrossbach/linkwise` (NOT
     * `arturrossbach/statamic-linkwise`). Returns null on any failure so
     * the frontend can hide the version line rather than render a guess.
     */
    private static function resolveVersion(): ?string
    {
        try {
            $addon = \Statamic\Facades\Addon::get('arturrossbach/linkwise');
            $version = $addon?->version();

            return is_string($version) && $version !== '' ? $version : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
