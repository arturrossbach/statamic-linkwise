<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Relink\RelinkService;
use Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Sync controller for the atomic re-link flow (Bug 17 Phase C).
 *
 * Single POST /cp/linkwise/relink → one RelinkService::relink call →
 * one save → one response. No JobLock, no exec, no async dispatch.
 * Bulk re-link is N sequential POSTs from the frontend, each one a
 * complete atomic unit — no cross-item lock contention.
 *
 * Replaces the previous trio that the DetailModal called in sequence:
 * (a) POST /cp/linkwise/url-changer/apply for Step 1 unlink,
 * (b) POST /cp/linkwise/{outbound,inbound}/insert for Step 2 async
 * insert, (c) POST /cp/linkwise/relink-preview for the Phase-A
 * partial-state safeguard. All three are now subsumed by this one
 * sync controller.
 */
class RelinkController extends CpController
{
    public function __construct(
        protected RelinkService $relinkService,
        protected EntryIndexer $indexer,
    ) {}

    public function relink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_id' => ['required', 'string', 'max:64'],
            'content_hash' => ['nullable', 'string', 'max:128'],

            // Original link (Step-A target — what gets unlinked)
            'original_href' => ['required', 'string', 'max:2048'],
            'occurrence_index' => ['required', 'integer', 'min:0'],
            'original_anchor' => ['required', 'string', 'max:500'],

            // New link (Step-C target — what gets inserted)
            'new_anchor' => ['required', 'string', 'max:500'],
            // target_entry_id (internal link) OR new_href (external) —
            // same convention as the legacy insert endpoints.
            'target_entry_id' => ['nullable', 'string', 'max:64'],
            'new_href' => ['nullable', 'string', 'max:2048'],

            // Scan-time sentence around the anchor; carried into the
            // activity-log snapshot's Context column. Originally a context-
            // fingerprint guard during insert; after the position-passing
            // refactor (Bug 17-20 root fix) the insert no longer needs it
            // and Step C uses the EXACT position returned by Step A.
            // Still validated + forwarded so reverts can show the same
            // context the editor saw at scan-time.
            'sentence_context' => ['nullable', 'string', 'max:1024'],

            // Note (REV-RL-01, 2026-05-13): the frontend still may send
            // `anchor_offset_in_context` because DashboardController emits
            // it for highlight-rendering. We accept-and-ignore via Laravel's
            // implicit "extra fields are not forwarded by $validated"
            // semantics — no validation rule, no service-call parameter.
            // Removed in REV-RL-01 sweep along with the matching 9
            // BardLinkInserter signatures + RelinkService param.

            // When this re-link is itself the revert of a recorded
            // relink snapshot, carries that snapshot's id. RelinkService
            // chains it into the new snapshot's summary + flips the
            // upstream snapshot's reverted_at via markReverted.
            'reverts' => ['nullable', 'string', 'max:64'],
        ]);

        if (empty($validated['target_entry_id']) && empty($validated['new_href'])) {
            return response()->json([
                'ok' => false,
                'reason' => 'invalid_request',
                'message' => 'target_entry_id oder new_href erforderlich.',
            ], 422);
        }

        $newHref = $validated['new_href']
            ?? 'statamic://entry::'.$validated['target_entry_id'];

        $result = $this->relinkService->relink(
            sourceEntryId: $validated['entry_id'],
            originalHref: $validated['original_href'],
            occurrenceIndex: (int) $validated['occurrence_index'],
            originalAnchor: $validated['original_anchor'],
            newAnchor: $validated['new_anchor'],
            newHref: $newHref,
            sentenceContext: $validated['sentence_context'] ?? null,
            expectedHash: $validated['content_hash'] ?? null,
            reverts: $validated['reverts'] ?? null,
        );

        // Index + cache refresh on success — same finalize-after-write
        // pattern as the 5 bulk commands. Sprint 6 follow-up
        // (user-reported 2026-05-16): without this, main-table counts
        // stay stale until the next full Scan Content, and re-opening
        // the DetailModal showed the OLD anchor-text (because the
        // entry's index record wasn't refreshed even though disk was).
        //
        // Affected entries = source + new target (when internal). The
        // OLD target is also affected (it lost an inbound) but we don't
        // always know its id at this point — for external→internal or
        // internal→internal re-links, the link mark stored the original
        // target. Pulling that out is a step we can defer; for V1 the
        // 5-min TTL on InboundSuggestionCache + the next scan handle it.
        if (($result['ok'] ?? false) === true) {
            $affectedIds = [$validated['entry_id']];
            if (! empty($validated['target_entry_id'])) {
                $affectedIds[] = $validated['target_entry_id'];
            }
            $this->refreshAfterRelink($affectedIds);
        }

        return response()->json($result);
    }

    /**
     * Mirrors the per-bulk-command `finalizeIndex` shape: forget cache
     * BEFORE the recompute (order matters — `computeSuggestionCountsForEntries`
     * internally calls `InboundEngine::suggestFiltered` which goes
     * through the cache), rebuild the index, then refresh counts.
     *
     * @param  list<string>  $affectedEntryIds
     */
    protected function refreshAfterRelink(array $affectedEntryIds): void
    {
        // Order: forget BEFORE recompute — see LinkInsertCommand for rationale.
        if (! empty($affectedEntryIds)) {
            try {
                app(InboundSuggestionCache::class)
                    ->forgetMany(array_map('strval', $affectedEntryIds));
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] RelinkController cache-forget failed: '.$e->getMessage());
            }
        }

        try {
            $previousCount = count($this->indexer->load());
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();

            // Empty-index guard mirrors the 5 bulk commands.
            if (count($records) === 0 && $previousCount > 0) {
                Log::warning(
                    '[Linkwise] RelinkController: refusing to save empty index (previous had '.$previousCount.' records)',
                );

                return;
            }
            $this->indexer->save($records);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] RelinkController index-rebuild failed: '.$e->getMessage());

            return;
        }

        if (! empty($affectedEntryIds)) {
            try {
                $this->indexer->computeSuggestionCountsForEntries($affectedEntryIds);
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] RelinkController suggestion-count refresh failed: '.$e->getMessage());
            }
        }
    }
}
