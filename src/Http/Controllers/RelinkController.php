<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Arturrossbach\Linkwise\Relink\RelinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

            // Scan-time sentence around the anchor; context-fingerprint
            // guard during insert to prevent silent wrong-occurrence wrap.
            'sentence_context' => ['nullable', 'string', 'max:1024'],

            // Bug 19 fix: anchor's character offset inside the captured
            // sentence_context. The context is a ±N-char window that can
            // span multiple identical occurrences; this offset pins the
            // valid match to the exact in-window position so a shrink-
            // re-link cannot wander to an earlier word-boundary match.
            // Optional — frontend sends null when extractor couldn't
            // pin the offset, in which case the old window-only filter
            // applies (Bug 19 unfixable in that case, but rare).
            'anchor_offset_in_context' => ['nullable', 'integer', 'min:0'],

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
            anchorOffsetInContext: isset($validated['anchor_offset_in_context'])
                ? (int) $validated['anchor_offset_in_context']
                : null,
        );

        return response()->json($result);
    }
}
