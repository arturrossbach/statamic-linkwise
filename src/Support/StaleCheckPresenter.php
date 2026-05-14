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

        return [
            'staleCheck' => [
                'is_stale' => $isStale,
                'index_built_at' => $indexAt,
                'broken_last_checked' => $brokenLastChecked,
                'check_url' => cp_route('linkwise.check-links'),
                'check_status_url' => cp_route('linkwise.check-links.status'),
            ],
        ];
    }
}
