<?php

namespace Arturrossbach\Linkwise\Http\Controllers\Dashboard;

use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Support\LogRotator;
use Arturrossbach\Linkwise\Support\PhpBinary;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Bulk-job dispatch + status + cancel endpoints.
 *
 * Extracted from {@see \Arturrossbach\Linkwise\Http\Controllers\DashboardController}
 * during REV-DR-01 Phase B PR 4. Houses 4 background-job trios (rebuildIndex /
 * checkLinks / bulkUnlink / detailUnlinkAsync — each with dispatch + status +
 * cancel) plus 2 standalone insert-cancel endpoints (inbound + outbound) whose
 * dispatch counterparts live in {@see InboundController}/{@see OutboundController}
 * but whose cancel-flag write follows the same pattern.
 *
 * Cross-job aggregation (`bulkStatus` over JobLock::JOBS, `bulkClear` force-clear)
 * lives in the sibling {@see JobsAggregatorController} — different read-only
 * surface, different invariants. They share a sub-namespace, nothing else.
 *
 * Design constraint (pre-merge review): **no shared dispatchBulkJob helper.**
 * Each dispatch has its own asymmetric pre-dispatch surface — most notably
 * detailUnlinkAsync's `Cache::forget(:status)` (lines around the put-status
 * call) which the other three trios intentionally skip. bulkUnlink dispatches
 * out of a full-page workflow where the user navigates away; detailUnlinkAsync
 * dispatches from a Modal with sub-second polling, so a millisecond-stale
 * terminal status would feed a false completion toast. Pinned by
 * {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\BulkPollingTest::test_detail_unlink_async_clears_stale_terminal_status_before_dispatch()}.
 *
 * Pre-merge parity check: `grep -c 'Cache::forget' BulkJobsController.php` MUST
 * equal 5 (1× checkLinks:cancel + 1× rebuildIndex:cancel + 1× bulkUnlink:cancel
 * + 2× detailUnlinkAsync:status + :cancel). A drop would mean a pre-forget got
 * silently collapsed during refactor.
 *
 * Behaviour pinned by {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\BulkPollingTest}
 * (28 cases / Phase A.2).
 */
class BulkJobsController extends CpController
{
    // ── Check Links trio ───────────────────────────────────────────────

    public function checkLinks(Request $request): JsonResponse
    {
        if ($active = JobLock::activeJob('check')) {
            return response()->json(JobLock::busyResponseData($active), 409);
        }

        // Spawn the check as a detached background process — web worker returns immediately.
        // This frees all session/file locks and doesn't block navigation or other CP requests.
        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PhpBinary::cli());
        $log = escapeshellarg(LogRotator::prepare('check-links.log', 'Check Links'));

        Cache::put('linkwise:check:status', ['phase' => 'starting'], 300);
        Cache::forget('linkwise:check:cancel');

        // `>> log 2>&1 &` appends + detaches — preserves prior runs so a
        // successful re-run doesn't wipe a failed run's evidence.
        exec("$php $artisan linkwise:check-links --progress >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Check started']);
    }

    public function checkLinksStatus(Request $request): JsonResponse
    {
        return response()->json(
            Cache::get('linkwise:check:status') ?? ['phase' => 'idle'],
        );
    }

    public function checkLinksCancel(Request $request): JsonResponse
    {
        Cache::put('linkwise:check:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    // ── Rebuild Index trio ─────────────────────────────────────────────

    public function rebuildIndex(Request $request): JsonResponse
    {
        if ($active = JobLock::activeJob('scan')) {
            return response()->json(JobLock::busyResponseData($active), 409);
        }

        // Spawn the scan as a detached background process — web worker returns immediately.
        // This frees all session/file locks and doesn't block navigation or other CP requests.
        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PhpBinary::cli());
        $log = escapeshellarg(LogRotator::prepare('scan-content.log', 'Scan Content'));

        Cache::put('linkwise:scan:status', ['phase' => 'starting'], 300);
        Cache::forget('linkwise:scan:cancel');

        exec("$php $artisan linkwise:index --progress >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Scan started']);
    }

    public function rebuildIndexStatus(Request $request): JsonResponse
    {
        return response()->json(
            Cache::get('linkwise:scan:status') ?? ['phase' => 'idle'],
        );
    }

    public function rebuildIndexCancel(Request $request): JsonResponse
    {
        Cache::put('linkwise:scan:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    // ── Bulk Unlink trio ───────────────────────────────────────────────

    public function bulkUnlink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'replacements' => 'required|array|min:1|max:5000',
            'replacements.*.entry_id' => 'required|string|max:64',
            'replacements.*.matched_url' => 'required|string|max:2048',
            'replacements.*.new_url' => 'required|string|max:2048',
            'replacements.*.field' => 'nullable|string|max:80',
            'replacements.*.field_type' => 'nullable|string|max:40',
            'replacements.*.occurrence_index' => 'nullable|integer',
            'replacements.*.search' => 'nullable|string|max:2048',
            // Anchor-fingerprint guard. Without this validation rule
            // Laravel strips anchor_text from $validated, the cache-payload
            // never carries it, and BulkUnlinkCommand's applySelected call
            // can't enforce the anchor check — the system would silently
            // unlink the wrong link if its position-index happened to match
            // a different link with the same URL (real bug, 2026-05-09).
            'replacements.*.anchor_text' => 'nullable|string|max:512',
            // Pre-flight hash check — same defensive pattern the other 6
            // bulk write paths (DetailUnlink, LinkInsert, UrlChangerApply,
            // ApplyRule, etc.) enforce. Optional because the legacy
            // broken-links-cleanup workflow doesn't always carry hashes;
            // when present, we fail-fast 409 on conflicts before dispatch
            // instead of silently overwriting an entry the user just
            // edited. Memory: feedback_bulk_writepath_standard.md.
            'entry_hashes' => 'sometimes|array|max:50000',
        ]);

        // Pre-flight conflict detection. Skipped when no hashes shipped
        // (legacy frontend / scripted callers) so we don't break those.
        $allHashes = $validated['entry_hashes'] ?? [];
        if (! empty($allHashes)) {
            $entryIds = array_flip(array_unique(array_column($validated['replacements'], 'entry_id')));
            $relevant = array_intersect_key($allHashes, $entryIds);
            $conflicts = SafeEntrySaver::verifyHashes($relevant);
            if (! empty($conflicts)) {
                $title = reset($conflicts);
                return response()->json([
                    'error' => 'conflict',
                    'message' => 'Entry "'.$title.'" was modified by another editor since the broken-links scan ran. Re-run the scan and try again.',
                    'entry_id' => array_key_first($conflicts),
                ], 409);
            }
        }

        // Scope-aware 409: only blocks if another job touches an OVERLAPPING
        // entry-set. Sprint 6 REV-BJ-03 — Editor A on post-1 doesn't block
        // Editor B on post-7.
        $bulkEntryIds = array_values(array_unique(array_filter(
            array_column($validated['replacements'], 'entry_id'),
            'is_string',
        )));
        if ($active = JobLock::activeJobConflicting('bulkunlink', $bulkEntryIds)) {
            return response()->json(JobLock::busyResponseData($active), 409);
        }

        $user = auth()->user();
        $validated['started_by'] = $user?->name() ?? $user?->email() ?? null;
        $validated['started_by_id'] = $user?->id() ?? null;
        Cache::put('linkwise:bulkunlink:payload', $validated, 600);
        Cache::put('linkwise:bulkunlink:status', [
            'phase' => 'starting',
            'total' => count($validated['replacements']),
        ], 600);
        Cache::forget('linkwise:bulkunlink:cancel');
        // Side-channel so other bulks' activeJobConflicting() check can see
        // which entries we're touching even when the command rewrites the
        // status payload during execution.
        JobLock::recordEntryIds('bulkunlink', $bulkEntryIds);

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PhpBinary::cli());
        $log = escapeshellarg(LogRotator::prepare('bulk-unlink.log', 'Bulk Unlink'));

        exec("$php $artisan linkwise:bulk-unlink >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Bulk unlink started']);
    }

    public function bulkUnlinkStatus(Request $request): JsonResponse
    {
        return response()->json(
            Cache::get('linkwise:bulkunlink:status') ?? ['phase' => 'idle'],
        );
    }

    public function bulkUnlinkCancel(Request $request): JsonResponse
    {
        Cache::put('linkwise:bulkunlink:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    // ── Detail Unlink (async) trio ─────────────────────────────────────

    /**
     * Trigger a DetailModal Bulk-Unlink as a single heavy job.
     *
     * Used by the per-entry detail modal's "Unlink selected" button. Same
     * heavy-pattern as URL Changer Apply: one POST, server iterates internally,
     * single banner with progress, single cancel, single completion banner.
     *
     * Concurrency: refuses while ANY heavy job is running (one-bulk-at-a-time
     * is enforced globally by JobLock).
     *
     * Asymmetry vs bulkUnlink: this dispatches from a Modal with sub-second
     * polling, so it MUST pre-forget `:status` to avoid feeding a stale
     * terminal phase ('done'/'cancelled') back to the modal as the new run's
     * "instantly completed" toast. bulkUnlink runs from a full-page flow
     * where the user navigates away — the same ms-window doesn't matter.
     */
    public function detailUnlinkAsync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'replacements' => 'required|array|min:1|max:5000',
            'replacements.*.entry_id' => 'required|string|max:64',
            'replacements.*.field' => 'nullable|string|max:80',
            'replacements.*.field_type' => 'nullable|string|max:40',
            'replacements.*.matched_url' => 'required|string|max:2048',
            'replacements.*.occurrence_index' => 'required|numeric|min:0',
            'replacements.*.search' => 'nullable|string|max:2048',
            // Anchor + sentence carried from DetailModal so the activity-
            // log drawer's Context column shows the editor's view at
            // unlink-time. Without these the column was rendering "—"
            // for every detail-unlink snapshot.
            'replacements.*.anchor_text' => 'nullable|string|max:512',
            'replacements.*.sentence_context' => 'nullable|string|max:1024',
            'entry_hashes' => 'sometimes|array|max:50000',
            'source_mode' => 'sometimes|in:inbound,outbound',
            'entry_title' => 'sometimes|nullable|string|max:300',
            // Activity-log Revert flow sends this to mark the new snapshot
            // as a reverse-of-X. Ignored otherwise.
            'reverts' => 'sometimes|nullable|string|max:64',
        ]);

        // Scope-aware 409: only blocks when an overlapping entry-set is busy.
        // Sprint 6 REV-BJ-03.
        $detailEntryIds = array_values(array_unique(array_filter(
            array_column($validated['replacements'], 'entry_id'),
            'is_string',
        )));
        if ($active = JobLock::activeJobConflicting('detailunlink', $detailEntryIds)) {
            return response()->json(JobLock::busyResponseData($active), 409);
        }

        // Hash conflicts: DON'T fail-fast 409 (that aborted the whole bulk
        // when a single entry was modified — Bug 9 2026-05-11). Instead
        // dispatch the job and let DetailUnlinkCommand's per-record
        // verifyHashes (line 157) skip the modified entries while the
        // others land. The user sees a clear "X removed, 1 skipped
        // (modified by editor)" toast at the end instead of an opaque
        // "everything cancelled because one entry changed" wall.
        // Same per-record skip pattern that the revert flow already used
        // — now applied to the normal bulk too.
        $allHashes = $validated['entry_hashes'] ?? [];

        $user = auth()->user();
        $startedBy = $user?->name() ?? $user?->email() ?? null;
        $startedById = $user?->id() ?? null;

        // Wipe stale terminal-status from a previous run.
        Cache::forget('linkwise:detailunlink:status');
        Cache::forget('linkwise:detailunlink:cancel');

        Cache::put('linkwise:detailunlink:payload', [
            'replacements' => $validated['replacements'],
            'entry_hashes' => $allHashes,
            'source_mode' => $validated['source_mode'] ?? 'inbound',
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'reverts' => $validated['reverts'] ?? null,
        ], 600);
        Cache::put('linkwise:detailunlink:status', [
            'phase' => 'starting',
            'total' => count($validated['replacements']),
            'current' => 0,
            'source_mode' => $validated['source_mode'] ?? 'inbound',
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ], 600);
        // Side-channel for cross-bulk conflict detection. Sprint 6 REV-BJ-03.
        JobLock::recordEntryIds('detailunlink', $detailEntryIds);

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PhpBinary::cli());
        $log = escapeshellarg(LogRotator::prepare('detail-unlink.log', 'Detail Unlink'));

        exec("$php $artisan linkwise:detail-unlink >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Detail unlink started']);
    }

    public function detailUnlinkStatus(Request $request): JsonResponse
    {
        return response()->json(
            Cache::get('linkwise:detailunlink:status') ?? ['phase' => 'idle'],
        );
    }

    public function detailUnlinkCancel(Request $request): JsonResponse
    {
        Cache::put('linkwise:detailunlink:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    // ── Insert-cancel (inbound + outbound) ─────────────────────────────

    /**
     * Cancel an in-flight inbound bulk-add. The LinkInsertCommand checks this
     * flag at the per-item boundary and exits cleanly with a 'cancelled'
     * status snapshot. Same lightweight-flag pattern as DetailUnlink + UrlChanger.
     *
     * The dispatch counterpart lives in {@see InboundController::insert()} —
     * only the cancel-flag write was clustered here with the other bulk
     * cancel-flag writes during the DC split. A later sweep may move this
     * back next to the dispatcher; not now (no empirical drift signal).
     */
    public function inboundInsertCancel(Request $request): JsonResponse
    {
        Cache::put('linkwise:inboundinsert:cancel', true, 60);

        return response()->json(['success' => true]);
    }

    /**
     * Cancel an in-flight outbound bulk-add — same flag pattern as inbound.
     */
    public function outboundInsertCancel(Request $request): JsonResponse
    {
        Cache::put('linkwise:outboundinsert:cancel', true, 60);

        return response()->json(['success' => true]);
    }
}
