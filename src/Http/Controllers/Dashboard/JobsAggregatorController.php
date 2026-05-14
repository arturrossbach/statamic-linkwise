<?php

namespace Arturrossbach\Linkwise\Http\Controllers\Dashboard;

use Arturrossbach\Linkwise\Support\JobLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Cross-controller heavy-job aggregation: status snapshot + force-clear.
 *
 * Extracted from {@see \Arturrossbach\Linkwise\Http\Controllers\DashboardController}
 * during REV-DR-01 Phase B PR 4. Separated from sibling
 * {@see BulkJobsController} because the surfaces are inverted:
 *
 * - BulkJobsController owns dispatch + per-job status + per-job cancel for the
 *   4 job-trios plus the 2 insert-cancel flags (16 cache-key writes).
 * - This controller READS across all 8 registered job-kinds via
 *   {@see JobLock::snapshot()} and assembles a single response the layout
 *   banner consumes. No per-job knowledge — `JobLock::JOBS` stays the
 *   single source of truth.
 *
 * Phase-A pin (architectural_health.md Klasse 1.aa risk #2):
 * `bulkStatus` aggregates 8 different job-status cache-keys via
 * `JobLock::snapshot()` and maps each kind → an existing cancel-route name
 * across DashboardController/AutoLinkController/UrlChangerController. The
 * cancel-URL lookup table here is the only inline coupling; it stays
 * inline because pickaxe history shows zero bugs where a new kind was
 * added to `JobLock::JOBS` and forgotten here. If that ever happens →
 * extract to `JobLock::cancelRouteFor()` (advisor pre-merge note).
 *
 * Behaviour pinned by {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\BulkPollingTest::test_bulk_status_aggregates_active_job_from_joblock_snapshot()}
 * + companion fallback test + bulk-clear tests.
 */
class JobsAggregatorController extends CpController
{
    /**
     * Unified status endpoint for ALL heavy bulk jobs (scan, check, bulk-unlink,
     * apply-rule, url-changer, detail-unlink, inbound-insert, outbound-insert).
     * Used by LinkwiseLayout's tab-spanning banner so the user sees one
     * consistent "something is running" indicator regardless of which job it
     * is or which tab they're on.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $snapshot = JobLock::snapshot();
        if (! $snapshot) {
            return response()->json(['phase' => 'idle']);
        }

        // Map kind → existing cancel route. The frontend cancel button uses
        // this URL directly so each kind keeps its own server-side cancel.
        $cancelUrls = [
            'scan' => cp_route('linkwise.rebuild-index.cancel'),
            'check' => cp_route('linkwise.check-links.cancel'),
            'bulkunlink' => cp_route('linkwise.bulk-unlink.cancel'),
            'applyrule' => cp_route('linkwise.autolink.apply-async.cancel'),
            'urlchanger' => cp_route('linkwise.url-changer.apply-cancel'),
            'detailunlink' => cp_route('linkwise.detail-unlink.cancel'),
            'inboundinsert' => cp_route('linkwise.inbound.insert.cancel'),
            'outboundinsert' => cp_route('linkwise.outbound.insert.cancel'),
        ];

        $status = $snapshot['status'];

        return response()->json([
            'kind' => $snapshot['name'],
            'label' => $snapshot['label'],
            'phase' => $status['phase'] ?? 'running',
            'current' => $status['current'] ?? 0,
            'total' => $status['total'] ?? 0,
            'message' => $status['message'] ?? null,
            'cancel_url' => $cancelUrls[$snapshot['name']] ?? null,
            'terminal' => $snapshot['terminal'],
            // Pass through the full status so the layout can read kind-specific
            // fields (e.g. apply-rule's links_added, rule_keyword) when building
            // the completion toast.
            'extra' => $status,
        ]);
    }

    /**
     * Force-clear a stuck heavy-job. Used by the "Operation seems stuck" UI
     * banner when a process crashed in a way the crash-guard missed (e.g.
     * server restart before shutdown_function fired) — without this, the
     * JobLock would hang on 'running' until cache TTL expires (typically 5-10
     * minutes), blocking all other bulks for the user.
     */
    public function bulkClear(Request $request, string $kind): JsonResponse
    {
        JobLock::forceClear($kind);

        return response()->json(['success' => true, 'cleared' => $kind]);
    }
}
