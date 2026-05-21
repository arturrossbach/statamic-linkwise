<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;

/**
 * Bulk content-hash fetch for entries.
 *
 * Why this exists:
 *   Klasse-7 C-1 residual race-closure (docs/ARCHITECTURE_REVIEW.md).
 *   The original C-1 fix (PR #49) added a post-bulk-completion
 *   `reloadEntries()` Inertia partial reload, but the reload roundtrip
 *   is async (~100-800ms). In that window, if the user opens a new
 *   DetailModal via `LinksReportTab::showDetail`, the synchronous read
 *   of `localEntries[].content_hash` still produces the OLD hash —
 *   next bulk-unlink ships OLD-hash, `verifyHashes` rejects per-record
 *   with `'modified'`, user gets the grey toast even though only
 *   Linkwise itself wrote.
 *
 *   `showDetail` becomes async, fetches fresh hashes from THIS endpoint
 *   for all entries involved in the modal BEFORE populating items.
 *   The fresh hashes are merged into `localEntries`. By the time the
 *   modal is interactive, hashes are guaranteed current.
 *
 * Read-only, idempotent, GET. Bulk by design — DetailModal's source
 * set is typically 5-50 entries; one fetch is faster than per-entry.
 * Unknown / deleted entries are silently skipped (frontend treats
 * missing-from-response as "no fresh hash available" and keeps its
 * cached value, which the existing render-path already handles via
 * `row.content_hash ?? ''`).
 */
class EntryHashesController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 500-cap matches the URL-length practicality threshold:
            // ~64-char UUIDs × 500 ≈ 32KB raw, ~36KB URL-encoded —
            // well within Apache's 8KB default but starts pushing
            // CDN limits at higher counts. Today's flows (DetailModal
            // source set, ~5-50 entries) stay well below. If a future
            // caller needs >500 in one shot, refactor to POST batch.
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'string|max:64',
        ]);

        $hashes = [];
        foreach ($validated['ids'] as $entryId) {
            try {
                $entry = Entry::find($entryId);
            } catch (\Throwable) {
                // Statamic flat-file envs without a primed Stache can
                // throw on Entry::find. Treat as unknown — silent skip.
                continue;
            }
            if ($entry) {
                $hashes[$entryId] = SafeEntrySaver::hash($entry);
            }
            // Unknown / deleted entries silently skipped. The frontend
            // contract is "absent key → no fresh hash → use cached".
        }

        return response()->json(['hashes' => $hashes]);
    }
}
