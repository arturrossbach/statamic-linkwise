<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkChecker;
use Arturrossbach\Linkwise\Links\BrokenLinkRecord;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;
use Statamic\Facades\Entry;

/**
 * Detached artisan command that applies a batch of URL replacements (apply or
 * unlink) on behalf of the URL Changer UI.
 *
 * Why heavy (server-side) instead of light (frontend loop):
 *  - URL Changer Apply can hit 500+ replacements on a real domain migration.
 *    A frontend loop dies on browser tab close, browser tab refresh, or user
 *    navigation away — losing all progress halfway.
 *  - Heavy survives all of those: the artisan process keeps running, the
 *    LinkwiseLayout poller picks up state on every Linkwise tab.
 *
 * The frontend triggers this via `linkwise.url-changer.apply-async` which
 * writes the payload to cache and shells out detached. Status flows back
 * through cache keys the layout poller already knows how to read.
 */
class UrlChangerApplyCommand extends Command
{
    protected $signature = 'linkwise:url-changer:apply';

    protected $description = 'Apply a queued URL Changer batch (replace or unlink) — invoked by the URL Changer UI';

    public function __construct(
        protected UrlReplacer $replacer,
        protected EntryIndexer $indexer,
        protected BrokenLinkReport $brokenReport,
        protected BrokenLinkChecker $brokenChecker,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Detached background commands shouldn't be killed by max_execution_time.
        @set_time_limit(0);
        // Crash-guard: if process dies before reaching a terminal phase,
        // mark status as 'error' so frontend recovers cleanly.
        JobLock::registerCrashGuard('linkwise:urlchanger:status', 'linkwise:urlchanger:payload');

        $payload = Cache::get('linkwise:urlchanger:payload');
        if (! is_array($payload)) {
            Cache::put('linkwise:urlchanger:status', [
                'phase' => 'error',
                'message' => 'No payload found in cache.',
            ], 120);

            return self::FAILURE;
        }

        $replacements = $payload['replacements'] ?? [];
        $search = $payload['search'] ?? '';
        $mode = $payload['mode'] ?? 'smart';
        $action = $payload['action'] ?? 'apply'; // 'apply' or 'unlink' — drives banner label
        $entryHashes = $payload['entry_hashes'] ?? [];
        $startedBy = $payload['started_by'] ?? null;
        $startedById = $payload['started_by_id'] ?? null;
        $reverts = $payload['reverts'] ?? null;
        $total = count($replacements);
        $succeeded = 0;
        $skipped = 0;
        // Per-entry skip records pushed back onto the ORIGINAL snapshot
        // when this run is a revert — see DetailUnlinkCommand for rationale.
        $revertSkippedRecords = [];
        // Forward-bulk skip records (drawer's "skipped during this run" table).
        $bulkSkippedRecords = [];
        $errors = [];
        // Source entry plus any internal targets parsed out of matched_url
        // (old) and new_url (replacement). Keeping both ends ensures
        // suggestion counts on every affected entry get refreshed by
        // finalizeIndex — old target loses an inbound, new target gains
        // one (or none for unlink).
        $affectedIds = [];

        $this->replacer->setMode($mode);

        // Group by entry — one save per entry for atomic per-entry semantics
        // (matches the behaviour the frontend had pre-migration).
        $byEntry = [];
        foreach ($replacements as $r) {
            $byEntry[$r['entry_id']][] = $r;
        }
        $entryGroups = array_values($byEntry);
        $totalEntries = count($entryGroups);

        // Forensic snapshot before any writes — entry IDs from the grouped
        // replacements, hashes from the payload's entry_hashes map.
        // items=[] starts empty; each confirmed replacement is appended via
        // appendWrittenItem so the activity-log only lists what actually
        // landed (skips no longer leak in as fake "URL replaced" rows).
        $snapshotId = app(BulkSnapshotStore::class)->record(
            kind: 'urlchanger',
            entryIds: array_keys($byEntry),
            preHashes: is_array($entryHashes) ? array_intersect_key($entryHashes, $byEntry) : [],
            summary: array_filter([
                'action' => $action,
                'search' => $search,
                'mode' => $mode,
                'replacement_count' => $total,
                'reverts' => $reverts,
            ], fn ($v) => $v !== null),
            items: [],
            startedBy: $startedBy,
            startedById: $startedById,
        );

        Cache::put('linkwise:urlchanger:status', [
            'phase' => 'running',
            'current' => 0,
            'total' => $total, // counter in user-units (replacements), not entries
            'action' => $action,
            'search' => $search,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'heartbeat' => time(),
        ], 600);

        $processedReplacements = 0;

        foreach ($entryGroups as $idx => $entryReps) {
            if (Cache::get('linkwise:urlchanger:cancel')) {
                Cache::forget('linkwise:urlchanger:cancel');
                Cache::put('linkwise:urlchanger:status', [
                    'phase' => 'cancelled',
                    'total' => $total,
                    'current' => $processedReplacements,
                    'succeeded' => $succeeded,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'action' => $action,
                    'search' => $search,
                    'started_by' => $startedBy,
                ], 300);
                $this->finalizeIndex(array_keys($affectedIds));

                return self::SUCCESS;
            }

            $entryId = $entryReps[0]['entry_id'];
            $entryHashesForCall = isset($entryHashes[$entryId])
                ? [$entryId => $entryHashes[$entryId]]
                : [];

            // Pre-flight hash check — see DetailUnlinkCommand for rationale.
            // Without this, a user who edited an entry since the request was
            // built would see their edits silently merged with our URL change
            // (we'd replace the URL but keep their other edits). The activity-
            // log "modified entries are skipped" promise relies on this check.
            if (! empty($entryHashesForCall)) {
                $conflicts = \Arturrossbach\Linkwise\Support\SafeEntrySaver::verifyHashes($entryHashesForCall);
                if (! empty($conflicts)) {
                    $msg = 'Entry was modified by another editor';
                    $errors[$msg] = ($errors[$msg] ?? 0) + count($entryReps);
                    $skipped += count($entryReps);
                    $processedReplacements += count($entryReps);
                    $skipRec = BulkSnapshotStore::buildSkipRecord($entryId, 'modified');
                    $revertSkippedRecords[] = $skipRec;
                    $bulkSkippedRecords[] = $skipRec;
                    Cache::put('linkwise:urlchanger:status', [
                        'phase' => 'running',
                        'current' => $processedReplacements,
                        'total' => $total,
                        'succeeded' => $succeeded,
                        'skipped' => $skipped,
                        'action' => $action,
                        'search' => $search,
                        'started_by' => $startedBy,
                        'started_by_id' => $startedById,
                        'heartbeat' => time(),
                    ], 600);
                    continue;
                }
            }

            try {
                // applySelected handles per-entry hash check, atomic write.
                // We run it WITHOUT a per-call rebuild — we batch the rebuild
                // at the end via finalizeIndex().
                $result = $this->replacer->applySelected($search, $entryReps);

                $entryReplacementCount = count($entryReps);
                $actualReplacements = $result['total_replacements'] ?? 0;

                if ($actualReplacements === 0) {
                    // Backend OK but no actual replacement happened — links
                    // were already gone (index drift between preview and apply).
                    $msg = 'Links were already gone — Run Scan Content to refresh the index';
                    $errors[$msg] = ($errors[$msg] ?? 0) + $entryReplacementCount;
                    $skipped += $entryReplacementCount;
                } else {
                    $succeeded += $actualReplacements;
                    // Any unaccounted-for replacements (theoretical edge: requested 5,
                    // landed 3) count as skipped.
                    $missed = $entryReplacementCount - $actualReplacements;
                    if ($missed > 0) {
                        $msg = 'Some links were already gone';
                        $errors[$msg] = ($errors[$msg] ?? 0) + $missed;
                        $skipped += $missed;
                    }

                    // Append-on-success: snapshot.items grows only with
                    // confirmed replacements. The old upfront-record-all
                    // pattern leaked skipped rows into the activity-log.
                    foreach ($entryReps as $r) {
                        app(BulkSnapshotStore::class)->appendWrittenItem($snapshotId, [
                            'entry_id' => $r['entry_id'] ?? '',
                            'matched_url' => $r['matched_url'] ?? '',
                            'new_url' => $r['new_url'] ?? '',
                            'anchor_text' => $r['anchor_text'] ?? '',
                            'sentence_context' => $r['sentence_context'] ?? '',
                        ]);

                        $this->brokenReport->removeLink($r['entry_id'], $r['matched_url']);
                        $affectedIds[$r['entry_id']] = true;
                        // Old target loses an inbound (if internal).
                        if (preg_match('#^statamic://entry::([0-9a-f-]+)$#i', $r['matched_url'], $m)) {
                            $affectedIds[$m[1]] = true;
                        }
                        // New target gains an inbound (if internal — unlink
                        // sentinel is non-statamic so naturally won't match).
                        if (! empty($r['new_url']) && preg_match('#^statamic://entry::([0-9a-f-]+)$#i', $r['new_url'], $m)) {
                            $affectedIds[$m[1]] = true;
                        }
                    }
                }
            } catch (EntryConflictException $e) {
                $msg = 'Entry was modified by another editor';
                $errors[$msg] = ($errors[$msg] ?? 0) + count($entryReps);
                $skipped += count($entryReps);
                $skipRec = BulkSnapshotStore::buildSkipRecord($entryId, 'modified');
                $revertSkippedRecords[] = $skipRec;
                $bulkSkippedRecords[] = $skipRec;
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 120);
                $errors[$msg] = ($errors[$msg] ?? 0) + count($entryReps);
                $skipped += count($entryReps);
                $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord($entryId, 'error');
                Log::warning('[Linkwise] UrlChangerApplyCommand entry-error: '.$e->getMessage());
            }

            $processedReplacements += count($entryReps);
            Cache::put('linkwise:urlchanger:status', [
                'phase' => 'running',
                'current' => $processedReplacements,
                'total' => $total,
                'succeeded' => $succeeded,
                'skipped' => $skipped,
                'action' => $action,
                'search' => $search,
                'started_by' => $startedBy,
                'heartbeat' => time(),
            ], 600);
        }

        // Flip to 'indexing' before finalizeIndex so the banner shows
        // "Finalizing index…" instead of stuck-looking N/N.
        Cache::put('linkwise:urlchanger:status', [
            'phase' => 'indexing',
            'current' => $total,
            'total' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'action' => $action,
            'search' => $search,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'heartbeat' => time(),
        ], 600);

        $this->finalizeIndex(array_keys($affectedIds));

        app(BulkSnapshotStore::class)->recordPostHashesForEntries(
            $snapshotId,
            array_keys($byEntry),
        );
        app(BulkSnapshotStore::class)->markCompleted($snapshotId, [
            'phase' => 'done',
            'succeeded' => $succeeded,
            'skipped' => $skipped,
        ]);

        // Persist per-entry skip records onto THIS snapshot (always) and
        // — for revert flows — also onto the ORIGINAL snapshot. Drawer
        // surfaces them as a separate "skipped entries" table above the
        // main affected-entries list. See DetailUnlinkCommand for the
        // same pattern.
        if (! empty($bulkSkippedRecords)) {
            app(BulkSnapshotStore::class)->recordBulkSkipped($snapshotId, $bulkSkippedRecords);
        }
        // Revert flows only: write skip records onto the ORIGINAL snapshot.
        // OWN snapshot uses bulk_skipped (above). Bug 2026-05-11.
        if (! empty($revertSkippedRecords) && $reverts) {
            app(BulkSnapshotStore::class)->recordRevertSkipped($reverts, $revertSkippedRecords);
        }

        Cache::put('linkwise:urlchanger:status', [
            'phase' => 'done',
            'total' => $total,
            'current' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'errors' => $errors,
            'action' => $action,
            'search' => $search,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            // Root-level heartbeat — see LinkInsertCommand for rationale.
            'heartbeat' => time(),
            // 'extra' for frontend completionLabel + dedup-signature heartbeat.
            // Mirror LinkInsertCommand / DetailUnlinkCommand. Bug 2026-05-11.
            'extra' => [
                'succeeded' => $succeeded,
                'skipped' => $skipped,
                'errors' => $errors,
                'action' => $action,
                'search' => $search,
                'started_by' => $startedBy,
                'started_by_id' => $startedById,
                'heartbeat' => time(),
            ],
        ], 300);
        Cache::forget('linkwise:urlchanger:payload');

        return self::SUCCESS;
    }

    /**
     * One-time index rebuild after the whole batch completes.
     * Mirrors BulkUnlinkCommand::finalizeIndex — refuses to overwrite a
     * non-empty index with an empty one (detached process context can fail
     * to read Statamic content; better stale than wiped).
     *
     * @param  list<string>  $affectedEntryIds  Entries whose link relationships
     *   actually changed. Their suggestion counts get recomputed AFTER the
     *   index rebuild — buildIndex's default preserveSuggestionCounts copies
     *   OLD counts forward, so without targeted refresh the table keeps
     *   showing stale numbers for entries the URL change affected.
     */
    protected function finalizeIndex(array $affectedEntryIds = []): void
    {
        try {
            $previousCount = count($this->indexer->load());
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();

            if (count($records) === 0 && $previousCount > 0) {
                Log::warning(
                    '[Linkwise] UrlChangerApplyCommand: refusing to save empty index (previous had '.$previousCount.' records)',
                );

                return;
            }

            $this->indexer->save($records);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] UrlChangerApplyCommand finalizeIndex failed: '.$e->getMessage());

            return;
        }

        $cap = 20;
        if (! empty($affectedEntryIds) && count($affectedEntryIds) <= $cap) {
            try {
                $this->indexer->computeSuggestionCountsForEntries($affectedEntryIds);
            } catch (\Throwable $e) {
                Log::warning(
                    '[Linkwise] UrlChangerApplyCommand suggestion-count refresh failed: '.$e->getMessage(),
                );
            }
        } elseif (! empty($affectedEntryIds)) {
            Log::info(
                '[Linkwise] UrlChangerApplyCommand skipped suggestion-count refresh — '
                .count($affectedEntryIds).' affected entries exceeds cap of '.$cap
                .'. Counts will refresh at the next Scan Content.',
            );
        }
    }
}
