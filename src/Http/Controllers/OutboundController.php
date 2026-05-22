<?php

namespace Arturrossbach\Linkwise\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Suggestions\IgnoredSuggestionStore;
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
        protected IgnoredSuggestionStore $ignoredStore,
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

        // Persist TOTAL count to index (pre-ignored-filter) so the
        // badge subtraction in InertiaPagesController::links can compute
        // the displayed count as `total - ignoredCountFor(entryId)`.
        // This keeps the cache stable across ignore/un-ignore actions —
        // only the runtime subtraction layer changes when pairs get
        // ignored/un-ignored. See Klasse-10 guarantee-stack
        // (2026-05-22 launch session).
        if ($record->outboundSuggestionCount !== $result['count']) {
            $records[$entryId] = $record->withOutboundSuggestionCount($result['count']);
            $this->indexer->save($records);
        }

        // Decorate each target with an `is_ignored` flag so the
        // frontend can default-hide ignored pairs but reveal them
        // via the "Show ignored (N)" toggle. The pair is undirected
        // — `isIgnored(source, target)` matches regardless of which
        // side was the original ignore-initiator.
        //
        // We do NOT filter server-side: the modal needs both states
        // (visible + ignored) in one response so the toggle works
        // without a roundtrip. The default count subtraction happens
        // on the client side via a Vue computed.
        $ignoredCount = 0;
        foreach ($result['groups'] as &$group) {
            foreach ($group['targets'] as &$target) {
                $target['is_ignored'] = $this->ignoredStore->isIgnored(
                    $entryId, (string) ($target['target_entry_id'] ?? '')
                );
                if ($target['is_ignored']) {
                    $ignoredCount++;
                }
            }
        }
        unset($group, $target);
        $result['ignored_count'] = $ignoredCount;
        // result['count'] stays the TOTAL — frontend computes
        // visible = total - ignored_count for the header badge.

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
            // Bug A 2026-05-22: was set on $result but dropped from
            // the response. Frontend ignoredCount-Computed derives
            // it from the groups[].targets[].is_ignored flags, so
            // not strictly needed — but persisting the server-side
            // total in the response keeps the API symmetric with
            // InboundController and makes future debugging easier.
            'ignored_count' => $result['ignored_count'] ?? 0,
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
            // target_entry_id OR href — see InboundController for rationale.
            'insertions.*.target_entry_id' => ['nullable', 'string', 'max:64'],
            'insertions.*.href' => ['nullable', 'string', 'max:2048'],
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

        // Hash conflicts: DON'T fail-fast 409 (Bug 9 2026-05-11). Even
        // for the single-source outbound case, dispatch the job and let
        // LinkInsertCommand's per-record verifyHashes skip the inserts
        // with a clean "X added, Y skipped (entry modified)" toast.
        // Keeps UX consistent across all bulk flows.
        $expectedHash = $request->input('content_hash', '');

        // Outbound case: one source entry, many target inserts. The shared
        // LinkInsertCommand expects each item to carry its own
        // source_entry_id (so its loop body works for both modes); inject
        // the fixed source here before queuing the payload.
        //
        // sentence_context MUST flow through — BardLinkInserter uses it
        // as the visual-truth fingerprint to wrap the right occurrence
        // (commit c46cce3). Forgetting it here silently disabled the
        // entire fix; verified empirically 2026-05-10.
        $insertions = array_map(fn ($i) => [
            'source_entry_id' => $entryId,
            'target_entry_id' => $i['target_entry_id'] ?? null,
            'href' => $i['href'] ?? null,
            'anchor_text' => $i['anchor_text'],
            'sentence_context' => $i['sentence_context'] ?? null,
        ], $validated['insertions']);

        $user = auth()->user();
        $startedBy = $user?->name() ?? $user?->email() ?? null;
        $startedById = $user?->id() ?? null;

        // REV-XC-01 (2026-05-13): the previous 5-step boilerplate (cache-
        // forget stale status/cancel → put-payload → put-initial-status →
        // escapeshell args → exec) lived inline at 9 controller sites.
        // BulkJobDispatcher::dispatch owns the shape now; this site stays
        // a thin payload-assembly + dispatch.
        \Arturrossbach\Linkwise\Support\BulkJobDispatcher::dispatch(
            kind: 'outboundinsert',
            command: 'linkwise:link-insert',
            payload: [
                'source_mode' => 'outbound',
                'insertions' => $insertions,
                'entry_hashes' => $expectedHash ? [$entryId => $expectedHash] : [],
                'entry_title' => $validated['entry_title'] ?? '',
                'started_by' => $startedBy,
                'started_by_id' => $startedById,
                'reverts' => $validated['reverts'] ?? null,
            ],
            initialStatus: [
                'phase' => 'starting',
                'total' => count($insertions),
                'current' => 0,
                'source_mode' => 'outbound',
                'entry_title' => $validated['entry_title'] ?? '',
                'started_by' => $startedBy,
                'started_by_id' => $startedById,
            ],
            logFile: 'link-insert.log',
            logLabel: 'Link Insert',
        );

        return response()->json(['success' => true, 'message' => 'Outbound insert started']);
    }
}
