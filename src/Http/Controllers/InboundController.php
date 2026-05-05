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

    public function insert(Request $request): JsonResponse
    {
        // Refuse if a registered bulk job is running — they all rebuild the index
        // at the end and would race with these per-entry inserts.
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob()) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'entry_hashes' => ['sometimes', 'array'],
            'insertions' => ['required', 'array', 'min:1', 'max:200'],
            'insertions.*.source_entry_id' => ['required', 'string'],
            'insertions.*.target_entry_id' => ['required', 'string'],
            'insertions.*.anchor_text' => ['required', 'string'],
        ]);

        // Verify entry hashes before any modifications
        $conflicts = SafeEntrySaver::verifyHashes($request->input('entry_hashes', []));
        if (! empty($conflicts)) {
            $title = reset($conflicts);

            return response()->json([
                'error' => 'conflict',
                'message' => 'Entry "'.$title.'" was modified since this page loaded. Please reload and try again.',
            ], 409);
        }

        $results = [];

        foreach ($validated['insertions'] as $insertion) {
            try {
                $success = BardLinkInserter::insertLinkIntoEntry(
                    $insertion['source_entry_id'],
                    $insertion['anchor_text'],
                    $insertion['target_entry_id'],
                );

                $results[] = [
                    'source_entry_id' => $insertion['source_entry_id'],
                    'success' => $success,
                    'error' => $success ? null : 'Anchor text not found in entry. Try rebuilding the index.',
                ];
            } catch (EntryConflictException $e) {
                $results[] = [
                    'source_entry_id' => $insertion['source_entry_id'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] Failed to insert inbound link: '.$e->getMessage());

                $results[] = [
                    'source_entry_id' => $insertion['source_entry_id'],
                    'success' => false,
                    'error' => 'Failed to insert link.',
                ];
            }
        }

        // Rebuild index so outboundLinks reflect new links (skip on intermediate sequential requests)
        $anySuccess = collect($results)->contains('success', true);
        $skipRebuild = $request->boolean('skip_rebuild', false);

        if ($anySuccess && ! $skipRebuild) {
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();
            $this->indexer->save($records);
        }

        return response()->json(['results' => $results]);
    }
}
