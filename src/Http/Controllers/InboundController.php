<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Suggestions\InboundEngine;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

class InboundController extends CpController
{
    public function __construct(
        protected InboundEngine $inboundEngine,
        protected EntryIndexer $indexer,
    ) {}

    /**
     * Get inbound suggestions as JSON (for modal use).
     */
    public function suggestions(string $entryId): JsonResponse
    {
        $records = $this->indexer->load();
        $record = $records[$entryId] ?? null;

        if (! $record) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $suggestions = $this->inboundEngine->suggestFiltered($entryId);
        $suggestionCount = count($suggestions);

        // Persist count to index so table matches on next page load
        if ($record->inboundSuggestionCount !== $suggestionCount) {
            $records[$entryId] = $record->withInboundSuggestionCount($suggestionCount);
            $this->indexer->save($records);
        }

        $entryHashes = [];
        foreach ($suggestions as $s) {
            if (! isset($entryHashes[$s->sourceEntryId])) {
                $sourceEntry = Entry::find($s->sourceEntryId);
                $entryHashes[$s->sourceEntryId] = $sourceEntry ? SafeEntrySaver::hash($sourceEntry) : '';
            }
        }

        return response()->json([
            'entry_id' => $entryId,
            'entry_title' => $record->title,
            'entry_hashes' => $entryHashes,
            'suggestion_count' => $suggestionCount,
            'total_available' => $this->inboundEngine->getLastTotalCount(),
            'suggestions' => array_map(fn ($s) => array_merge($s->toArray(), [
                'source_edit_url' => cp_route('collections.entries.edit', [$s->sourceCollection, $s->sourceEntryId]),
            ]), $suggestions),
        ]);
    }

    /**
     * Trigger an Inbound bulk-add as a heavy job — same dispatch pattern as
     * DetailUnlink / URL-Changer Apply / Apply Rule. Returns immediately
     * after exec()ing the artisan command in the background; the frontend
     * picks up real progress via the unified bulk-status poller.
     */
    public function insert(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('inboundinsert')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'entry_hashes' => ['sometimes', 'array', 'max:50000'],
            'insertions' => ['required', 'array', 'min:1', 'max:200'],
            'insertions.*.source_entry_id' => ['required', 'string', 'max:64'],
            // target_entry_id OR href — either may be present (LinkInsertCommand
            // builds the effective href from whichever is provided). Internal
            // re-links use target_entry_id, external re-links (revert of
            // detail-unlink with https:// URLs) use href.
            'insertions.*.target_entry_id' => ['nullable', 'string', 'max:64'],
            'insertions.*.href' => ['nullable', 'string', 'max:2048'],
            'insertions.*.anchor_text' => ['required', 'string', 'max:500'],
            // Sentence around the anchor at preview-time. Carried into the
            // activity-log snapshot so the drawer's Context column shows the
            // editor's view at apply-time, even after the entry has changed.
            'insertions.*.sentence_context' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'entry_title' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Activity-log Revert flow — marks the new snapshot as a reverse-of-X.
            'reverts' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        // Hash conflicts: DON'T fail-fast 409 (Bug 9 2026-05-11 — that
        // aborted the whole bulk when one entry was modified). Dispatch
        // anyway and let LinkInsertCommand's per-record verifyHashes
        // skip the modified entries while the others land. User sees a
        // mixed-result toast at the end instead of "everything cancelled".

        $user = auth()->user();
        $startedBy = $user?->name() ?? $user?->email() ?? null;
        $startedById = $user?->id() ?? null;

        // REV-XC-01 (2026-05-13): single helper for the dispatch boilerplate;
        // see OutboundController::insert for rationale.
        \Arturrossbach\Linkwise\Support\BulkJobDispatcher::dispatch(
            kind: 'inboundinsert',
            command: 'linkwise:link-insert',
            payload: [
                'source_mode' => 'inbound',
                'insertions' => $validated['insertions'],
                'entry_hashes' => $validated['entry_hashes'] ?? [],
                'entry_title' => $validated['entry_title'] ?? '',
                'started_by' => $startedBy,
                'started_by_id' => $startedById,
                'reverts' => $validated['reverts'] ?? null,
            ],
            initialStatus: [
                'phase' => 'starting',
                'total' => count($validated['insertions']),
                'current' => 0,
                'source_mode' => 'inbound',
                'entry_title' => $validated['entry_title'] ?? '',
                'started_by' => $startedBy,
                'started_by_id' => $startedById,
            ],
            logFile: 'link-insert.log',
            logLabel: 'Link Insert',
        );

        return response()->json(['success' => true, 'message' => 'Inbound insert started']);
    }
}
