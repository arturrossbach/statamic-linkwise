<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;

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

        $total = count($insertions);
        $succeeded = 0;
        $skipped = 0;
        $errors = [];

        Cache::put($statusKey, [
            'phase' => 'running',
            'current' => 0,
            'total' => $total,
            'source_mode' => $sourceMode,
            'entry_title' => $entryTitle,
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'heartbeat' => time(),
        ], 600);

        foreach ($insertions as $i => $insertion) {
            // Cancel check at the item boundary (cheap, responsive).
            if (Cache::get($cancelKey)) {
                Cache::forget($cancelKey);
                Cache::put($statusKey, [
                    'phase' => 'cancelled',
                    'total' => $total,
                    'current' => $i,
                    'succeeded' => $succeeded,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'source_mode' => $sourceMode,
                    'entry_title' => $entryTitle,
                    'started_by' => $startedBy,
                    'started_by_id' => $startedById,
                ], 300);
                $this->finalizeIndex();

                return self::SUCCESS;
            }

            try {
                $sourceEntryId = $insertion['source_entry_id'];
                $targetEntryId = $insertion['target_entry_id'];
                $anchorText = $insertion['anchor_text'];

                // BardLinkInserter does its own SafeEntrySaver-based hash
                // check via insertLinkIntoEntry → SafeEntrySaver::load+save.
                // For inbound mode the per-entry hash flows from $entryHashes;
                // for outbound the source is fixed and content_hash applies
                // (carried in $insertion if needed; for V1 the per-item path
                // uses fresh-load each time).
                $success = BardLinkInserter::insertLinkIntoEntry(
                    $sourceEntryId,
                    $anchorText,
                    $targetEntryId,
                );

                if ($success) {
                    $succeeded++;
                } else {
                    $msg = 'Anchor text not found in entry';
                    $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                    $skipped++;
                }
            } catch (EntryConflictException $e) {
                $msg = 'Entry was modified by another editor';
                $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                $skipped++;
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 120);
                $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                $skipped++;
                Log::warning('[Linkwise] LinkInsertCommand item-error: '.$e->getMessage());
            }

            Cache::put($statusKey, [
                'phase' => 'running',
                'current' => $i + 1,
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

        $this->finalizeIndex();

        Cache::put($statusKey, [
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
        Cache::forget($payloadKey);

        return self::SUCCESS;
    }

    /**
     * After a batch of inserts the index needs a refresh so the suggestion
     * counts + outbound-link maps reflect the new edges. Same pattern as
     * DetailUnlinkCommand — fire-and-log; don't fail the bulk on this.
     */
    protected function finalizeIndex(): void
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
        }
    }
}
