<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Links\BrokenLinkReport;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;

/**
 * Detached artisan command for the DetailModal's "Bulk Unlink" — remove a
 * batch of inbound or outbound links from one (outbound) or many (inbound)
 * entries in a single heavy job.
 *
 * Mirrors UrlChangerApplyCommand's structure (status-cache, heartbeat, crash-
 * guard, finalize-once-at-end). Separate kind 'detailunlink' so the banner
 * shows "Removing links from 'Article Title'" instead of generic URL-Changer
 * verbiage — the user clicked a per-entry detail dialog, not the URL Changer
 * tab.
 */
class DetailUnlinkCommand extends Command
{
    protected $signature = 'linkwise:detail-unlink';

    protected $description = 'Apply a queued DetailModal bulk-unlink batch (invoked by the Detail Modal UI)';

    public function __construct(
        protected UrlReplacer $replacer,
        protected EntryIndexer $indexer,
        protected BrokenLinkReport $brokenReport,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @set_time_limit(0);
        JobLock::registerCrashGuard('linkwise:detailunlink:status', 'linkwise:detailunlink:payload');

        $payload = Cache::get('linkwise:detailunlink:payload');
        if (! is_array($payload)) {
            Cache::put('linkwise:detailunlink:status', [
                'phase' => 'error',
                'message' => 'No payload found in cache.',
            ], 120);

            return self::FAILURE;
        }

        $replacements = $payload['replacements'] ?? [];
        $entryHashes = $payload['entry_hashes'] ?? [];
        $sourceMode = $payload['source_mode'] ?? 'inbound'; // banner verbiage
        $entryTitle = $payload['entry_title'] ?? '';
        $startedBy = $payload['started_by'] ?? null;
        $startedById = $payload['started_by_id'] ?? null;

        $total = count($replacements);
        $succeeded = 0;
        $skipped = 0;
        $errors = [];
        // Entries whose link relationships actually changed (the entry
        // being modified + the target, if the removed URL is internal).
        // Without targeted refresh, suggestion counts on those entries
        // would stay frozen at the pre-unlink values — same stale-table
        // class as LinkInsertCommand.
        $affectedIds = [];

        // Use exact mode — DetailModal sends the full URL of each link, no
        // domain inference / fuzzy matching wanted.
        $this->replacer->setMode('exact');

        // Group by entry — one save per entry for atomic per-entry semantics.
        $byEntry = [];
        foreach ($replacements as $r) {
            $byEntry[$r['entry_id']][] = $r;
        }
        $entryGroups = array_values($byEntry);

        // Forensic snapshot — see BulkSnapshotStore. Recorded BEFORE writes
        // so the activity-log can show "this bulk affected entries X, Y, Z"
        // even if the bulk later crashes or is cancelled mid-flight.
        $snapshotItems = array_map(fn (array $r) => [
            'entry_id' => $r['entry_id'] ?? '',
            'matched_url' => $r['matched_url'] ?? '',
            'anchor_text' => $r['anchor_text'] ?? '',
        ], $replacements);
        $snapshotId = app(BulkSnapshotStore::class)->record(
            kind: 'detailunlink',
            entryIds: array_keys($byEntry),
            preHashes: array_intersect_key($entryHashes, $byEntry),
            summary: [
                'source_mode' => $sourceMode,
                'entry_title' => $entryTitle,
                'replacement_count' => $total,
            ],
            items: $snapshotItems,
            startedBy: $startedBy,
            startedById: $startedById,
        );

        Cache::put('linkwise:detailunlink:status', [
            'phase' => 'running',
            'current' => 0,
            'total' => $total,
            'source_mode' => $sourceMode,
            'entry_title' => $entryTitle,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'heartbeat' => time(),
        ], 600);

        $processedReplacements = 0;

        foreach ($entryGroups as $entryReps) {
            if (Cache::get('linkwise:detailunlink:cancel')) {
                Cache::forget('linkwise:detailunlink:cancel');
                Cache::put('linkwise:detailunlink:status', [
                    'phase' => 'cancelled',
                    'total' => $total,
                    'current' => $processedReplacements,
                    'succeeded' => $succeeded,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'source_mode' => $sourceMode,
                    'entry_title' => $entryTitle,
                    'started_by' => $startedBy,
                    'started_by_id' => $startedById,
                ], 300);
                $this->finalizeIndex(array_keys($affectedIds));

                return self::SUCCESS;
            }

            $entryId = $entryReps[0]['entry_id'];
            $entryHashesForCall = isset($entryHashes[$entryId])
                ? [$entryId => $entryHashes[$entryId]]
                : [];

            try {
                // applySelected handles per-entry hash check and atomic save.
                // Search arg is empty — we use exact match per replacement
                // via the matched_url. Inject the UNLINK sentinel as new_url
                // so applySelected → replaceNthInBard / Markdown / Replicator
                // fall into the "remove the link mark" branches instead of
                // hitting an undefined `new_url` index. The DetailModal UI
                // never sends new_url because the action is purely a removal.
                $entryRepsForCall = array_map(
                    fn (array $r) => $r + ['new_url' => UrlHelper::UNLINK],
                    $entryReps,
                );
                $result = $this->replacer->applySelected('', $entryRepsForCall);

                $entryReplacementCount = count($entryReps);
                $actualReplacements = $result['total_replacements'] ?? 0;

                if ($actualReplacements === 0) {
                    $msg = 'Links were already gone — Run Scan Content to refresh the index';
                    $errors[$msg] = ($errors[$msg] ?? 0) + $entryReplacementCount;
                    $skipped += $entryReplacementCount;
                } else {
                    $succeeded += $actualReplacements;
                    $missed = $entryReplacementCount - $actualReplacements;
                    if ($missed > 0) {
                        $msg = 'Some links were already gone';
                        $errors[$msg] = ($errors[$msg] ?? 0) + $missed;
                        $skipped += $missed;
                    }

                    // Update broken-link report — remove old URLs.
                    foreach ($entryReps as $r) {
                        $this->brokenReport->removeLink($r['entry_id'], $r['matched_url']);
                        $affectedIds[$r['entry_id']] = true;
                        // Internal links (statamic://entry::ID) → the
                        // target's inbound counts also shift; record it.
                        if (preg_match('#^statamic://entry::([0-9a-f-]+)$#i', $r['matched_url'], $m)) {
                            $affectedIds[$m[1]] = true;
                        }
                    }
                }
            } catch (EntryConflictException $e) {
                $msg = 'Entry was modified by another editor';
                $errors[$msg] = ($errors[$msg] ?? 0) + count($entryReps);
                $skipped += count($entryReps);
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 120);
                $errors[$msg] = ($errors[$msg] ?? 0) + count($entryReps);
                $skipped += count($entryReps);
                Log::warning('[Linkwise] DetailUnlinkCommand entry-error: '.$e->getMessage());
            }

            $processedReplacements += count($entryReps);
            Cache::put('linkwise:detailunlink:status', [
                'phase' => 'running',
                'current' => $processedReplacements,
                'total' => $total,
                'succeeded' => $succeeded,
                'skipped' => $skipped,
                'source_mode' => $sourceMode,
                'entry_title' => $entryTitle,
                'started_by' => $startedBy,
                'started_by_id' => $startedById,
                'heartbeat' => time(),
            ], 600);
        }

        // Flip phase to 'indexing' BEFORE finalizeIndex so the banner
        // shows "Finalizing index…" instead of sitting stuck at N/N for
        // the 1-3min the rebuild can take on large sites.
        Cache::put('linkwise:detailunlink:status', [
            'phase' => 'indexing',
            'current' => $total,
            'total' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'source_mode' => $sourceMode,
            'entry_title' => $entryTitle,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'heartbeat' => time(),
        ], 600);

        $this->finalizeIndex(array_keys($affectedIds));

        // Record post-bulk hashes so the activity-log can detect future user
        // edits without false-positives on the bulk's own writes.
        app(BulkSnapshotStore::class)->recordPostHashesForEntries(
            $snapshotId,
            array_keys($byEntry),
        );

        Cache::put('linkwise:detailunlink:status', [
            'phase' => 'done',
            'total' => $total,
            'current' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'errors' => $errors,
            'source_mode' => $sourceMode,
            'entry_title' => $entryTitle,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
        ], 300);
        Cache::forget('linkwise:detailunlink:payload');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $affectedEntryIds  Entries whose link relationships
     *   actually changed during this run. Their suggestion counts are
     *   recomputed AFTER the index rebuild — buildIndex's default
     *   preserveSuggestionCounts copies the OLD counts forward, so without
     *   targeted refresh the table keeps showing pre-unlink suggestion
     *   numbers (e.g., "80 inbound" right after the user removed those
     *   80 inbound links).
     */
    protected function finalizeIndex(array $affectedEntryIds = []): void
    {
        try {
            $previousCount = count($this->indexer->load());
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();

            if (count($records) === 0 && $previousCount > 0) {
                Log::warning(
                    '[Linkwise] DetailUnlinkCommand: refusing to save empty index (previous had '.$previousCount.' records)',
                );

                return;
            }

            $this->indexer->save($records);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] DetailUnlinkCommand finalizeIndex failed: '.$e->getMessage());

            return;
        }

        $cap = 20;
        if (! empty($affectedEntryIds) && count($affectedEntryIds) <= $cap) {
            try {
                $this->indexer->computeSuggestionCountsForEntries($affectedEntryIds);
            } catch (\Throwable $e) {
                Log::warning(
                    '[Linkwise] DetailUnlinkCommand suggestion-count refresh failed: '.$e->getMessage(),
                );
            }
        } elseif (! empty($affectedEntryIds)) {
            Log::info(
                '[Linkwise] DetailUnlinkCommand skipped suggestion-count refresh — '
                .count($affectedEntryIds).' affected entries exceeds cap of '.$cap
                .'. Counts will refresh at the next Scan Content.',
            );
        }
    }
}
