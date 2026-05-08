<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Suggestions\OutboundSuggestionGrouper;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
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
     * Trigger an Outbound bulk-add as a heavy job — same dispatch pattern
     * as Inbound + DetailUnlink + URL-Changer Apply. Returns immediately;
     * the unified bulk-status poller picks up real progress.
     */
    public function insert(Request $request): JsonResponse
    {
        if ($active = \Arturrossbach\Linkwise\Support\JobLock::activeJob('outboundinsert')) {
            return response()->json(\Arturrossbach\Linkwise\Support\JobLock::busyResponseData($active), 409);
        }

        $validated = $request->validate([
            'entry_id' => ['required', 'string', 'max:64'],
            'content_hash' => ['sometimes', 'string', 'max:64'],
            'insertions' => ['required', 'array', 'min:1', 'max:200'],
            'insertions.*.target_entry_id' => ['required', 'string', 'max:64'],
            'insertions.*.anchor_text' => ['required', 'string', 'max:500'],
            // Sentence around the anchor at preview-time — fed through to
            // the activity-log Context column. See InboundController for the
            // matching field on the inbound side.
            'insertions.*.sentence_context' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'entry_title' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Activity-log Revert flow — marks the new snapshot as a reverse-of-X.
            'reverts' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $entryId = $validated['entry_id'];

        // Optimistic locking check on the source entry (for outbound the
        // single source is shared across all insertions, so one hash check
        // covers the whole batch — fail-fast 409 before dispatch).
        // Skipped for revert flows (per-entry conflicts → skips, not abort).
        $expectedHash = $request->input('content_hash', '');
        if ($expectedHash && empty($validated['reverts'])) {
            $entry = Entry::find($entryId);
            if ($entry && SafeEntrySaver::hash($entry) !== $expectedHash) {
                return response()->json([
                    'error' => 'conflict',
                    'message' => 'Entry was modified since this page loaded. Please reload and try again.',
                ], 409);
            }
        }

        // Outbound case: one source entry, many target inserts. The shared
        // LinkInsertCommand expects each item to carry its own
        // source_entry_id (so its loop body works for both modes); inject
        // the fixed source here before queuing the payload.
        $insertions = array_map(fn ($i) => [
            'source_entry_id' => $entryId,
            'target_entry_id' => $i['target_entry_id'],
            'anchor_text' => $i['anchor_text'],
        ], $validated['insertions']);

        $user = auth()->user();
        $startedBy = $user?->name() ?? $user?->email() ?? null;
        $startedById = $user?->id() ?? null;

        // Wipe stale terminal-status from a previous run.
        \Illuminate\Support\Facades\Cache::forget('linkwise:outboundinsert:status');
        \Illuminate\Support\Facades\Cache::forget('linkwise:outboundinsert:cancel');

        \Illuminate\Support\Facades\Cache::put('linkwise:outboundinsert:payload', [
            'source_mode' => 'outbound',
            'insertions' => $insertions,
            'entry_hashes' => $expectedHash ? [$entryId => $expectedHash] : [],
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'reverts' => $validated['reverts'] ?? null,
        ], 600);
        \Illuminate\Support\Facades\Cache::put('linkwise:outboundinsert:status', [
            'phase' => 'starting',
            'total' => count($insertions),
            'current' => 0,
            'source_mode' => 'outbound',
            'entry_title' => $validated['entry_title'] ?? '',
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ], 600);

        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PHP_BINARY);
        $log = escapeshellarg(\Arturrossbach\Linkwise\Support\LogRotator::prepare('link-insert.log', 'Link Insert'));

        exec("$php $artisan linkwise:link-insert >> $log 2>&1 &");

        return response()->json(['success' => true, 'message' => 'Outbound insert started']);
    }
}
