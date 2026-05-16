<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\BulkStatusWriter;
use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Statamic\Facades\Entry;

/**
 * Detached artisan command for the SuggestionModal's bulk "Add link" — both
 * inbound (write a link FROM each source entry TO one target) and outbound
 * (write multiple anchored links FROM one source entry TO various targets).
 *
 * Mirrors DetailUnlinkCommand exactly: status-cache writes per item, heart-
 * beat, crash-guard, finalize-once-at-end. Single command for both modes
 * because the loop body is identical (each item = entry-load + bard-traverse
 * + insert + atomic save) — only the kind of insertion differs.
 *
 * Two JobLock kinds (inboundinsert / outboundinsert) so the running banner
 * shows the correct label and the user can track which mode is active. The
 * status-cache key is selected dynamically based on the payload's
 * source_mode field (inbound | outbound).
 */
class LinkInsertCommand extends Command
{
    protected $signature = 'linkwise:link-insert';

    protected $description = 'Apply a queued SuggestionModal bulk link-insert batch (invoked by the Inbound/Outbound modal UI)';

    public function __construct(
        protected EntryIndexer $indexer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @set_time_limit(0);

        // Pull the payload first to learn which mode (inbound/outbound) we
        // were dispatched for, so we can route status writes to the correct
        // cache key for the matching JobLock kind.
        $payloadInbound = Cache::get('linkwise:inboundinsert:payload');
        $payloadOutbound = Cache::get('linkwise:outboundinsert:payload');
        $payload = $payloadInbound ?: $payloadOutbound;
        if (! is_array($payload)) {
            // Neither key has a payload — nothing to do; cache may have
            // expired. Don't crash, just exit cleanly. The frontend will
            // see no transition out of 'starting' and the heartbeat-stuck
            // detector will surface a recovery banner after 120s.
            return self::FAILURE;
        }

        $sourceMode = $payload['source_mode'] ?? 'inbound';
        $kind = $sourceMode === 'outbound' ? 'outboundinsert' : 'inboundinsert';
        $statusKey = "linkwise:{$kind}:status";
        $payloadKey = "linkwise:{$kind}:payload";
        $cancelKey = "linkwise:{$kind}:cancel";

        JobLock::registerCrashGuard($statusKey, $payloadKey);

        $insertions = $payload['insertions'] ?? [];
        $entryHashes = $payload['entry_hashes'] ?? [];
        $entryTitle = $payload['entry_title'] ?? '';
        $startedBy = $payload['started_by'] ?? null;
        $startedById = $payload['started_by_id'] ?? null;
        $reverts = $payload['reverts'] ?? null;

        $total = count($insertions);
        $succeeded = 0;
        $skipped = 0;
        // Per-entry skip records pushed back onto the ORIGINAL snapshot
        // when this run is a revert. See DetailUnlinkCommand for rationale.
        $revertSkippedRecords = [];
        // Per-entry skip records for the drawer's "skipped during this run"
        // table — populated for ALL skip reasons (anchor not found, hash
        // mismatch, missing payload field, exception), regardless of revert
        // mode. Bug 2026-05-11: previously only revert-mode populated
        // revert_skipped, so non-revert bulks left their skipped entries
        // invisible to the user beyond an aggregate count.
        $bulkSkippedRecords = [];
        $errors = [];
        // Entries whose link relationships actually changed (source +
        // target of every successful insertion). Used by finalizeIndex
        // to recompute their suggestion counts so the table doesn't
        // keep showing stale "80 inbound suggestions" after the user
        // just inserted those 80 links — the candidates have become
        // actual links and need to drop out of the suggestion pool.
        $affectedIds = [];

        // Forensic snapshot before any writes. The set of touched entries is
        // the union of source_entry_ids in the insertions list — for inbound
        // mode each insertion targets a different SOURCE; for outbound mode
        // they all share the same source (the modal's entry).
        $touchedSources = array_values(array_unique(array_filter(array_map(
            fn ($i) => is_array($i) ? ($i['source_entry_id'] ?? null) : null,
            $insertions,
        ))));
        // Append-on-success pattern: items=[] at start, grows via
        // appendWrittenItem on each confirmed insert. See BulkSnapshotStore
        // for rationale — keeps the activity-log honest about what we wrote
        // even when conflicts skip individual items mid-run.
        $snapshotId = app(BulkSnapshotStore::class)->record(
            kind: $kind,
            entryIds: $touchedSources,
            preHashes: is_array($entryHashes) ? array_intersect_key($entryHashes, array_flip($touchedSources)) : [],
            summary: array_filter([
                'source_mode' => $sourceMode,
                'entry_title' => $entryTitle,
                'insertion_count' => $total,
                'reverts' => $reverts,
            ], fn ($v) => $v !== null),
            items: [],
            startedBy: $startedBy,
            startedById: $startedById,
        );

        // REV-OB-01 (2026-05-13): BulkStatusWriter replaces 7 near-identical
        // Cache::put($statusKey, [...]) sites with a single helper. Context
        // fields stay constant across all phase writes; per-phase methods
        // (running/cancelled/indexing/done) own the shape + TTL + heartbeat
        // + done-mirror invariants. Adding a new status field is now a 1-
        // place edit instead of 7.
        $status = new BulkStatusWriter($statusKey, [
            'source_mode' => $sourceMode,
            'entry_title' => $entryTitle,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ]);

        $status->running(current: 0, total: $total);

        foreach ($insertions as $i => $insertion) {
            // Cancel check at the item boundary (cheap, responsive).
            if (Cache::get($cancelKey)) {
                Cache::forget($cancelKey);
                $status->cancelled($i, $total, $succeeded, $skipped, $errors);
                $this->finalizeIndex(array_keys($affectedIds));

                return self::SUCCESS;
            }

            try {
                $sourceEntryId = $insertion['source_entry_id'];
                $targetEntryId = $insertion['target_entry_id'] ?? null;
                $href = $insertion['href'] ?? null;
                $anchorText = $insertion['anchor_text'];

                // Two routing modes — same write path under the hood
                // (BardLinkInserter::insertLinkIntoEntryWithHref handles
                // BOTH internal statamic://entry:: refs AND arbitrary URLs).
                // Internal: build href from target_entry_id. External:
                // href passed directly (used by detail-unlink revert for
                // external URLs — re-wraps the anchor in the original URL).
                $effectiveHref = $href ?? ($targetEntryId ? 'statamic://entry::' . $targetEntryId : null);
                if (! $effectiveHref) {
                    $msg = 'Insertion missing both target_entry_id and href';
                    $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                    $skipped++;
                    $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord($sourceEntryId, 'missing_link_target');
                    $status->running($i + 1, $total, $succeeded, $skipped);
                    continue;
                }

                // Pre-flight hash check: if the entry has been modified
                // since the snapshot we're reverting from (or since the
                // request payload was built), skip with a "modified" reason
                // instead of letting BardLinkInserter overwrite the user's
                // edits. The activity-log UI promises this — DetailUnlink
                // and UrlChangerApply enforce it the same way. Without this
                // check, a revert that the drawer marked as skippable would
                // silently apply anyway, re-inserting links into entries
                // the user explicitly edited after the original bulk.
                if (isset($entryHashes[$sourceEntryId]) && $entryHashes[$sourceEntryId] !== '') {
                    $conflicts = \Arturrossbach\Linkwise\Support\SafeEntrySaver::verifyHashes(
                        [$sourceEntryId => $entryHashes[$sourceEntryId]],
                    );
                    if (! empty($conflicts)) {
                        $msg = 'Entry was modified by another editor';
                        $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                        $skipped++;
                        $skipRec = BulkSnapshotStore::buildSkipRecord($sourceEntryId, 'modified');
                        $revertSkippedRecords[] = $skipRec;
                        $bulkSkippedRecords[] = $skipRec;
                        $status->running($i + 1, $total, $succeeded, $skipped);
                        continue;
                    }
                }

                $success = BardLinkInserter::insertLinkIntoEntryWithHref(
                    $sourceEntryId,
                    $anchorText,
                    $effectiveHref,
                    caseSensitive: false,
                    save: true,
                    // Visual-truth guard (2026-05-10): when the suggestion's
                    // scan captured a specific sentence context, BardLinkInserter
                    // must wrap the anchor INSIDE that sentence, not the first
                    // matching occurrence anywhere in the entry. Prevents
                    // silent wrong-position writes when a parallel edit
                    // prepended a 2nd occurrence of the anchor.
                    expectedSentenceContext: $insertion['sentence_context'] ?? null,
                );

                if ($success) {
                    $succeeded++;
                    // Append-on-success: only confirmed inserts make it
                    // into the snapshot.items list — guarantees the
                    // activity-log table reflects reality.
                    app(BulkSnapshotStore::class)->appendWrittenItem($snapshotId, [
                        'source_entry_id' => $sourceEntryId,
                        'target_entry_id' => $targetEntryId ?? '',
                        'href' => $effectiveHref,
                        'anchor_text' => $anchorText,
                        'sentence_context' => $insertion['sentence_context'] ?? '',
                    ]);
                    // Both ends of the new edge are affected — source's
                    // outbound counts shift, target's inbound counts shift
                    // (only for internal targets — external href has no
                    // counterpart to refresh).
                    $affectedIds[$sourceEntryId] = true;
                    if ($targetEntryId) $affectedIds[$targetEntryId] = true;
                    // Self-edit hash advance: this insert just changed the
                    // source entry's content → the disk-hash now diverges
                    // from the frontend-supplied hash in $entryHashes. The
                    // NEXT item in the same bulk that targets the same source
                    // entry (the common Outbound case: many suggestions, one
                    // source) would otherwise see "Modified by another editor"
                    // and skip — even though the only "other editor" was the
                    // PREVIOUS iteration of this same loop.
                    //
                    // Wrapped in its own try/catch + placed AFTER the success
                    // counter + after appendWrittenItem on purpose: if this
                    // refresh ever throws, the outer try would catch it and
                    // double-count the item (1 succeeded + 1 skipped). Stale
                    // hash → next item still skipped → recoverable. Double
                    // count → activity-log lies forever → not recoverable.
                    if (isset($entryHashes[$sourceEntryId])) {
                        try {
                            $refreshed = Entry::find($sourceEntryId);
                            if ($refreshed) {
                                $entryHashes[$sourceEntryId] = \Arturrossbach\Linkwise\Support\SafeEntrySaver::hash($refreshed);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('[Linkwise] LinkInsertCommand: hash-advance refresh failed — '.$e->getMessage());
                        }
                    }
                } else {
                    $msg = 'Anchor text not found in entry';
                    $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                    $skipped++;
                    $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord($sourceEntryId, 'anchor_not_found');
                }
            } catch (EntryConflictException $e) {
                $msg = 'Entry was modified by another editor';
                $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                $skipped++;
                $skipRec = BulkSnapshotStore::buildSkipRecord($sourceEntryId, 'modified');
                $revertSkippedRecords[] = $skipRec;
                $bulkSkippedRecords[] = $skipRec;
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 120);
                $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                $skipped++;
                $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord($sourceEntryId, 'error');
                Log::warning('[Linkwise] LinkInsertCommand item-error: '.$e->getMessage());
            }

            $status->running($i + 1, $total, $succeeded, $skipped);
        }

        // Flip to 'indexing' before finalizeIndex so the banner shows
        // "Finalizing index…" instead of stuck N/N during the rebuild.
        $status->indexing($total, $succeeded, $skipped);

        $this->finalizeIndex(array_keys($affectedIds));

        app(BulkSnapshotStore::class)->recordPostHashesForEntries($snapshotId, $touchedSources);
        app(BulkSnapshotStore::class)->markCompleted($snapshotId, [
            'phase' => 'done',
            'succeeded' => $succeeded,
            'skipped' => $skipped,
        ]);

        // Persist per-entry skip records for THIS bulk run — populated for
        // every skip reason (anchor not found, hash mismatch, missing
        // target, exception) regardless of revert flow. Drawer renders
        // these in a "X entries were skipped during this run" table above
        // the main affected-entries list.
        if (! empty($bulkSkippedRecords)) {
            app(BulkSnapshotStore::class)->recordBulkSkipped($snapshotId, $bulkSkippedRecords);
        }

        // Revert flows only: write the skip records onto the ORIGINAL
        // snapshot so its drawer shows "of N items someone tried to revert,
        // here are the M that we left untouched". Bug 2026-05-11: we used
        // to ALSO write to OWN snapshot, which produced a duplicate "skipped
        // during this run" table next to the new bulk_skipped one (which
        // already covers own-snapshot semantics).
        if (! empty($revertSkippedRecords) && $reverts) {
            app(BulkSnapshotStore::class)->recordRevertSkipped($reverts, $revertSkippedRecords);
        }

        $status->done($total, $succeeded, $skipped, $errors);
        Cache::forget($payloadKey);

        return self::SUCCESS;
    }

    /**
     * After a batch of inserts the index needs a refresh so the suggestion
     * counts + outbound-link maps reflect the new edges. Same pattern as
     * DetailUnlinkCommand — fire-and-log; don't fail the bulk on this.
     */
    /**
     * @param  list<string>  $affectedEntryIds  Ids whose link relationships
     *   actually changed during this run (source + target of every
     *   successful insertion). Their suggestion counts get recomputed
     *   AFTER the index rebuild — buildIndex's default preserveSuggestionCounts
     *   path copies the OLD counts forward, so without this targeted
     *   refresh the table keeps showing stale "80 inbound suggestions"
     *   for the entry the user just added 80 inbound links to.
     */
    protected function finalizeIndex(array $affectedEntryIds = []): void
    {
        try {
            $previousCount = count($this->indexer->load());
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();

            // Empty-index guard mirrors DetailUnlinkCommand: if a buildIndex
            // crash returns 0 records when we previously had data, refuse
            // to overwrite — the user would lose all suggestion + outbound
            // counts. Better to keep stale data and surface a warning than
            // wipe the index because of a transient error.
            if (count($records) === 0 && $previousCount > 0) {
                Log::warning(
                    '[Linkwise] LinkInsertCommand: refusing to save empty index (previous had '.$previousCount.' records)',
                );

                return;
            }

            $this->indexer->save($records);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] LinkInsertCommand finalizeIndex failed: '.$e->getMessage());

            return;
        }

        // Targeted suggestion-count refresh for affected entries.
        // Each entry's recompute iterates the full corpus + dry-runs every
        // candidate, so cost scales linearly per affected entry. At ~80
        // entries the user observed the bulk hanging at "80/80" for
        // minutes — the loop runs but nothing user-visible advances.
        // Cap to a small batch so the typical workflow (1–20 inserts)
        // still gets immediate count updates while large bulks finish
        // quickly with counts that catch up at the next scan.
        $cap = 20;
        if (! empty($affectedEntryIds) && count($affectedEntryIds) <= $cap) {
            try {
                $this->indexer->computeSuggestionCountsForEntries($affectedEntryIds);
            } catch (\Throwable $e) {
                Log::warning(
                    '[Linkwise] LinkInsertCommand suggestion-count refresh failed: '.$e->getMessage(),
                );
            }
        } elseif (! empty($affectedEntryIds)) {
            Log::info(
                '[Linkwise] LinkInsertCommand skipped suggestion-count refresh — '
                .count($affectedEntryIds).' affected entries exceeds cap of '.$cap
                .'. Counts will refresh at the next Scan Content.',
            );
        }

        // Inbound-suggestion-cache invalidation (Sprint 6 REV-IB-01 follow-up,
        // user-reported 2026-05-16: "Nach Bulk-Insert muss ich erst rescannen,
        // sonst zeigt das Inbound-Modal die schon-eingefügten Suggestions
        // weiter und der Count in der Haupttabelle steht still"). Affects
        // both source AND target sides because either could be queried by
        // `InboundEngine::suggestFiltered()` afterwards. Runs unconditionally
        // — even if the count-refresh above hit the cap, the cache still
        // needs invalidating so the next modal/API call recomputes fresh.
        if (! empty($affectedEntryIds)) {
            try {
                app(\Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache::class)
                    ->forgetMany(array_map('strval', $affectedEntryIds));
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] LinkInsertCommand cache-forget failed: '.$e->getMessage());
            }
        }
    }
}
