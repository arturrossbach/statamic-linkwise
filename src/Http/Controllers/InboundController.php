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
            'insertions.*.target_entry_id' => ['required', 'string', 'max:64'],
            'insertions.*.anchor_text' => ['required', 'string', 'max:500'],
            'entry_title' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Activity-log Revert flow — marks the new snapshot as a reverse-of-X.
            'reverts' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        // Pre-flight hash check — fail-fast 409 before dispatch instead of
        // letting the loop hit conflicts mid-run. Skipped for revert flows
        // (those tolerate per-entry conflicts as skips, see DashboardController
        // ::detailUnlinkAsync for the rationale).
        if (empty($validated['reverts'])) {
            $conflicts = SafeEntrySaver::verifyHashes($request->input('entry_hashes', []));
            if (! empty($conflicts)) {
                $title = reset($conflicts);

                return response()->json([
                    'error' => 'conflict',
                    'message' => 'Entry "'.$title.'" was modified since this page loaded. Please reload and try again.',
                ], 409);
            }
        }

        $user = auth()->user();
        $startedBy = $user?->name() ?? $user?->email() ?? null;
        $startedById = $user?->id() ?? null;

        // Wipe stale terminal-status from a previous run so the poller
        // doesn't confuse it with the new dispatch.
        \Illuminate\Support\Facades\Cache::forget('linkwise:inboundinsert:status');
        \Illuminate\Support\Facades\Cache::forget('linkwise:inboundinsert:cancel');

        \Illuminate\Support\Facades\Cache::put('linkwise:inboundinsert:payload', [
            'source_mode' => 'inbound',
            'insertions' => $validated['insertions'],
            'entry_hashes' => $validated['entry_hashes'] ?? [],
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'reverts' => $validated['reverts'] ?? null,
        ], 600);
        \Illuminate\Support\Facades\Cache::put('linkwise:inboundinsert:status', [
            'phase' => 'starting',
            'total' => count($validated['insertions']),
            'current' => 0,
            'source_mode' => 'inbound',
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ], 600);

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Arturrossbach\Linkwise\Support\LogRotator::prepare('link-insert.log', 'Link Insert'));

        exec("$php $artisan linkwise:link-insert >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Inbound insert started']);
    }
}
