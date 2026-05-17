<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;

/**
 * Detached artisan command that applies a batch of unlink/replace operations.
 * Invoked by DashboardController::bulkUnlink() which writes the payload to
 * cache and dispatches this command via `exec(... &)`.
 *
 * Progress is reported back through the cache so the frontend can poll and
 * survive tab switches / reloads without losing the running job.
 */
class BulkUnlinkCommand extends Command
{
    protected $signature = 'linkwise:bulk-unlink';

    protected $description = 'Apply a queued batch of unlink/replace operations (invoked by the Broken Links UI)';

    public function __construct(
        protected UrlReplacer $replacer,
        protected EntryIndexer $indexer,
        protected BrokenLinkReport $brokenReport,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Detached background commands shouldn't be killed by max_execution_time.
        @set_time_limit(0);
        // Crash-guard: if process dies before reaching a terminal phase,
        // mark status as 'error' so the frontend can recover instead of
        // showing a stuck banner for the full cache TTL.
        JobLock::registerCrashGuard('linkwise:bulkunlink:status', 'linkwise:bulkunlink:payload');

        $payload = Cache::get('linkwise:bulkunlink:payload');
        if (! is_array($payload)) {
            Cache::put('linkwise:bulkunlink:status', [
                'phase' => 'error',
                'message' => 'No payload found in cache.',
            ], 120);

            return self::FAILURE;
        }

        $replacements = $payload['replacements'] ?? [];
        $entryHashes = $payload['entry_hashes'] ?? [];
        $total = count($replacements);
        $succeeded = 0;
        $skipped = 0;
        $errors = [];
        // Per-entry skip records for the drawer's "skipped during this run"
        // table — Bug 2026-05-11.
        $bulkSkippedRecords = [];
        // Source entries (entry_id) plus internal-link targets parsed from
        // the matched_url. Refreshed by finalizeIndex so suggestion counts
        // on both ends drop after the unlink.
        $affectedIds = [];

        // Forensic snapshot before any writes — recorded entry IDs are the
        // unique sources the bulk plans to touch.
        $entryIds = array_values(array_unique(array_filter(
            array_column($replacements, 'entry_id'),
            'is_string',
        )));
        // Append-on-success — items grows only on confirmed unlink writes.
        // Even though bulkunlink isn't revertable, keeping the activity-log
        // honest (only listing real removals) is the same hygiene as other
        // bulk paths.
        $snapshotId = app(BulkSnapshotStore::class)->record(
            kind: 'bulkunlink',
            entryIds: $entryIds,
            preHashes: is_array($entryHashes) ? array_intersect_key($entryHashes, array_flip($entryIds)) : [],
            summary: ['replacement_count' => $total],
            items: [],
        );

        Cache::put('linkwise:bulkunlink:status', [
            'phase' => 'running',
            'current' => 0,
            'total' => $total,
            'heartbeat' => time(),
        ], 600);

        foreach ($replacements as $i => $r) {
            if (Cache::get('linkwise:bulkunlink:cancel')) {
                Cache::forget('linkwise:bulkunlink:cancel');
                Cache::put('linkwise:bulkunlink:status', [
                    'phase' => 'cancelled',
                    'total' => $total,
                    'succeeded' => $succeeded,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ], 300);
                $this->finalizeIndex(array_keys($affectedIds));

                return self::SUCCESS;
            }

            Cache::put('linkwise:bulkunlink:status', [
                'phase' => 'running',
                'current' => $i + 1,
                'total' => $total,
                'heartbeat' => time(),
            ], 600);

            try {
                $entryId = $r['entry_id'] ?? '';

                // Pre-flight hash check (REV-BJ-04, 2026-05-13). CLAUDE.md
                // "Bulk-Write-Path Standard" Punkt 2: verifyHashes per-record
                // in the loop, never fail-fast 409 in the controller. Without
                // this, BulkUnlink was the only mutating bulk command that
                // ignored its own entry_hashes payload — the late check
                // inside UrlReplacer::applySelected uses the disk-hash
                // captured at SafeEntrySaver::load() time, NOT the editor's
                // frontend-hash from when they clicked. Concurrent edits
                // therefore landed silently: the unlink applied against a
                // newer state than the editor saw, sometimes hitting a link
                // they never selected. feedback_no_silent_overwrite.md.
                //
                // Pattern mirrors LinkInsertCommand Z. 199–224 exactly so
                // the skip-with-reason 'modified' lands in the same drawer
                // table across all 5 bulk commands.
                if ($entryId !== ''
                    && isset($entryHashes[$entryId])
                    && $entryHashes[$entryId] !== ''
                ) {
                    $conflicts = \Arturrossbach\Linkwise\Support\SafeEntrySaver::verifyHashes(
                        [$entryId => $entryHashes[$entryId]],
                    );
                    if (! empty($conflicts)) {
                        $msg = 'Entry was modified — please reload';
                        $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                        $skipped++;
                        // Klasse-7 follow-up (activity_log_skip_context_gap,
                        // 2026-05-17): anchor + matched_url carry the
                        // identity of the specific link the unlink would
                        // have removed, so the Activity-Log Drawer can
                        // surface which one was skipped. matched_url is
                        // the target — may be internal (statamic://entry::X)
                        // or external (https://…); the renderer resolves.
                        $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord(
                            $entryId, 'modified',
                            $r['anchor_text'] ?? null,
                            null,
                            $r['matched_url'] ?? null,
                        );
                        continue;
                    }
                }

                $result = $this->replacer->applySelected($r['search'] ?? $r['matched_url'], [$r]);
                $this->brokenReport->removeLink($r['entry_id'], $r['matched_url']);

                if (($result['total_replacements'] ?? 0) === 0) {
                    // URL was no longer in the entry (e.g. removed by another editor between scan and bulk).
                    // Don't count as succeeded — the user's intent "unlink this specific link" wasn't fulfilled,
                    // even though the broken-links report is now consistent.
                    $msg = 'Link was no longer in entry';
                    $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                    $skipped++;
                    $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord(
                        $r['entry_id'] ?? '', 'anchor_not_found',
                        $r['anchor_text'] ?? null,
                        null,
                        $r['matched_url'] ?? null,
                    );
                } else {
                    $succeeded++;
                    // Append-on-success: confirmed removal lands in items.
                    app(BulkSnapshotStore::class)->appendWrittenItem($snapshotId, [
                        'entry_id' => $r['entry_id'] ?? '',
                        'matched_url' => $r['matched_url'] ?? '',
                        'sentence_context' => $r['sentence_context'] ?? '',
                    ]);
                    $affectedIds[$r['entry_id']] = true;
                    if (preg_match('#^statamic://entry::([0-9a-f-]+)$#i', $r['matched_url'], $m)) {
                        $affectedIds[$m[1]] = true;
                    }
                }
            } catch (EntryConflictException $e) {
                $msg = 'Entry was modified — please reload';
                $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                $skipped++;
                $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord(
                    $r['entry_id'] ?? '', 'modified',
                    $r['anchor_text'] ?? null,
                    null,
                    $r['matched_url'] ?? null,
                );
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 120);
                $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                $skipped++;
                $bulkSkippedRecords[] = BulkSnapshotStore::buildSkipRecord(
                    $r['entry_id'] ?? '', 'error',
                    $r['anchor_text'] ?? null,
                    null,
                    $r['matched_url'] ?? null,
                );
            }
        }

        if (! empty($bulkSkippedRecords)) {
            app(BulkSnapshotStore::class)->recordBulkSkipped($snapshotId, $bulkSkippedRecords);
        }

        // Flip to 'indexing' before finalizeIndex so the banner shows
        // "Finalizing index…" instead of N/N during the rebuild.
        Cache::put('linkwise:bulkunlink:status', [
            'phase' => 'indexing',
            'current' => $total,
            'total' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'heartbeat' => time(),
        ], 600);

        $this->finalizeIndex(array_keys($affectedIds));

        app(BulkSnapshotStore::class)->recordPostHashesForEntries($snapshotId, $entryIds);
        app(BulkSnapshotStore::class)->markCompleted($snapshotId, [
            'phase' => 'done',
            'succeeded' => $succeeded,
            'skipped' => $skipped,
        ]);

        Cache::put('linkwise:bulkunlink:status', [
            'phase' => 'done',
            'total' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'errors' => $errors,
            // Root-level heartbeat — bulkStatus maps full cache to frontend
            // `extra`, dedup signature reads tExtra.heartbeat from here.
            'heartbeat' => time(),
            // 'extra' so frontend completionLabel/dedup reads non-empty
            // counts + a per-run heartbeat. Without it back-to-back
            // identical bulks deduped each other and the persistent
            // banner went missing. Bug 2026-05-11.
            'extra' => [
                'succeeded' => $succeeded,
                'skipped' => $skipped,
                'errors' => $errors,
                'heartbeat' => time(),
            ],
        ], 300);
        Cache::forget('linkwise:bulkunlink:payload');

        return self::SUCCESS;
    }

    /**
     * One-time index rebuild after all replacements complete.
     * Guards against wiping a valid index: if the rebuild yields zero records
     * while the previous index had records, something is wrong — skip the save.
     *
     * @param  list<string>  $affectedEntryIds  Entries whose link relationships
     *   actually changed. Their suggestion counts get recomputed AFTER the
     *   index rebuild — buildIndex's default preserveSuggestionCounts copies
     *   the OLD counts forward, so without targeted refresh the table keeps
     *   showing pre-unlink suggestion numbers.
     */
    protected function finalizeIndex(array $affectedEntryIds = []): void
    {
        try {
            $previousCount = count($this->indexer->load());
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();

            if (count($records) === 0 && $previousCount > 0) {
                // Refuse to overwrite a non-empty index with an empty one.
                // Usually means Statamic's content couldn't be read in the detached
                // process context — we'd rather keep the stale-but-valid index.
                \Illuminate\Support\Facades\Log::warning(
                    '[Linkwise] BulkUnlinkCommand: refusing to save empty index (previous had '.$previousCount.' records)',
                );

                return;
            }

            $this->indexer->save($records);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Linkwise] BulkUnlinkCommand finalizeIndex failed: '.$e->getMessage());

            return;
        }

        // Order: forget BEFORE recompute — see LinkInsertCommand for rationale.
        if (! empty($affectedEntryIds)) {
            try {
                app(\Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache::class)
                    ->forgetMany(array_map('strval', $affectedEntryIds));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Linkwise] BulkUnlinkCommand cache-forget failed: '.$e->getMessage());
            }
        }

        $cap = 20;
        if (! empty($affectedEntryIds) && count($affectedEntryIds) <= $cap) {
            try {
                $this->indexer->computeSuggestionCountsForEntries($affectedEntryIds);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Linkwise] BulkUnlinkCommand suggestion-count refresh failed: '.$e->getMessage(),
                );
            }
        } elseif (! empty($affectedEntryIds)) {
            \Illuminate\Support\Facades\Log::info(
                '[Linkwise] BulkUnlinkCommand skipped suggestion-count refresh — '
                .count($affectedEntryIds).' affected entries exceeds cap of '.$cap
                .'. Counts will refresh at the next Scan Content.',
            );
        }
    }
}
