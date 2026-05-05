<?php

namespace Inkline\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inkline\Linkwise\Exceptions\EntryConflictException;
use Inkline\Linkwise\Indexer\EntryIndexer;
use Inkline\Linkwise\Suggestions\OutboundSuggestionGrouper;
use Inkline\Linkwise\Suggestions\SuggestionEngine;
use Inkline\Linkwise\Support\BardLinkInserter;
use Inkline\Linkwise\Support\SafeEntrySaver;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

class OutboundController extends CpController
{
    public function __construct(
        protected EntryIndexer $indexer,
        protected SuggestionEngine $engine,
    ) {}

    /**
     * Get outbound suggestions as JSON (for modal use).
     */
    public function suggestions(string $entryId): JsonResponse
    {
        $records = $this->indexer->load();
        $record = $records[$entryId] ?? null;

        if (! $record) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $alreadyLinked = $record->outboundLinks;
        $suggestions = $this->engine->suggest($record->text, $records, $entryId, $alreadyLinked);

        $entry = Entry::find($entryId);

        // Group + filter via single source of truth
        $result = OutboundSuggestionGrouper::groupAndFilter($suggestions, $entryId);

        // Persist count to index so table matches on next page load
        if ($record->outboundSuggestionCount !== $result['count']) {
            $records[$entryId] = $record->withOutboundSuggestionCount($result['count']);
            $this->indexer->save($records);
        }

        // Add edit URLs to each target
        foreach ($result['groups'] as &$group) {
            foreach ($group['targets'] as &$target) {
                $targetEntry = Entry::find($target['target_entry_id']);
                $target['target_edit_url'] = $targetEntry
                    ? cp_route('collections.entries.edit', [$target['target_collection'], $target['target_entry_id']])
                    : null;
            }
            unset($target);
        }
        unset($group);

        return response()->json([
            'entry_id' => $entryId,
            'entry_title' => $record->title,
            'content_hash' => $entry ? SafeEntrySaver::hash($entry) : '',
            'groups' => $result['groups'],
            'group_count' => $result['count'],
        ]);
    }

    /**
     * Insert outbound links into the entry.
     * Each insertion places a link at the anchor text position in the entry's content.
     */
    public function insert(Request $request): JsonResponse
    {
        // Refuse if a registered bulk job is running — they all rebuild the index
        // at the end and would race with these per-entry inserts.
        if ($active = \Inkline\Linkwise\Support\JobLock::activeJob()) {
            return response()->json(\Inkline\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'entry_id' => ['required', 'string'],
            'content_hash' => ['sometimes', 'string'],
            'insertions' => ['required', 'array', 'min:1', 'max:50'],
            'insertions.*.target_entry_id' => ['required', 'string'],
            'insertions.*.anchor_text' => ['required', 'string'],
        ]);

        $entryId = $validated['entry_id'];

        // Optimistic locking check
        $expectedHash = $request->input('content_hash', '');
        if ($expectedHash) {
            $entry = Entry::find($entryId);
            if ($entry && SafeEntrySaver::hash($entry) !== $expectedHash) {
                return response()->json([
                    'error' => 'conflict',
                    'message' => 'Entry was modified since this page loaded. Please reload and try again.',
                ], 409);
            }
        }

        $results = [];

        foreach ($validated['insertions'] as $insertion) {
            try {
                $success = BardLinkInserter::insertLinkIntoEntry(
                    $entryId,
                    $insertion['anchor_text'],
                    $insertion['target_entry_id'],
                );

                $results[] = [
                    'target_entry_id' => $insertion['target_entry_id'],
                    'success' => $success,
                    'error' => $success ? null : 'Anchor text not found in entry. Try rebuilding the index.',
                ];
            } catch (EntryConflictException $e) {
                $results[] = [
                    'target_entry_id' => $insertion['target_entry_id'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                \Log::warning('[Linkwise] Failed to insert outbound link: '.$e->getMessage());

                $results[] = [
                    'target_entry_id' => $insertion['target_entry_id'],
                    'success' => false,
                    'error' => 'Failed to insert link.',
                ];
            }
        }

        // Rebuild index so outboundLinks reflect new links
        if (collect($results)->contains('success', true)) {
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();
            $this->indexer->save($records);
        }

        return response()->json(['results' => $results]);
    }
}
