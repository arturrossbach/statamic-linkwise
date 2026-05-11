<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\AutoLink\AutoLinkApplier;
use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;

/**
 * Detached artisan command that applies a single auto-link rule to all matching entries.
 * Invoked by AutoLinkController::apply (async path) which writes the payload to cache
 * and dispatches this command via `exec(... &)`.
 *
 * Progress is reported via Cache so the frontend can poll, survive tab switches
 * and reloads, and offer cancellation.
 */
class ApplyRuleCommand extends Command
{
    protected $signature = 'linkwise:apply-rule';

    protected $description = 'Apply a queued auto-link rule (invoked by the Auto-Linking UI)';

    public function __construct(
        protected AutoLinkManager $manager,
        protected EntryIndexer $indexer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Detached background commands shouldn't be killed by PHP's
        // max_execution_time. Bulk-runs across hundreds of entries can take
        // minutes; the default 30s would trigger a hard kill mid-job, leaving
        // entries half-modified and the JobLock stuck on 'running'.
        @set_time_limit(0);

        // If the process dies (segfault, OOM, server restart, kill -9), our
        // normal status-cache writes never reach 'done'. Frontend would see
        // a stale 'running' for the full TTL. Register a shutdown-time guard
        // that flips the phase to 'error' if we never reached a terminal one.
        JobLock::registerCrashGuard('linkwise:applyrule:status', 'linkwise:applyrule:payload');

        $payload = Cache::get('linkwise:applyrule:payload');
        if (! is_array($payload)) {
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'error',
                'message' => 'No payload found in cache.',
            ], 120);

            return self::FAILURE;
        }

        // Multi-rule mode: payload has `rule_ids` array → iterate sequentially,
        // single JobLock, single banner, nested progress (rule X of Y).
        // Used by "Apply Selected" in the Auto-Linking tab.
        if (isset($payload['rule_ids']) && is_array($payload['rule_ids'])) {
            return $this->handleMultiple($payload);
        }

        // Legacy single-rule mode: payload has `rule_id` → process one rule.
        // Used by per-row Apply button in the Auto-Linking tab.
        if (! isset($payload['rule_id'])) {
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'error',
                'message' => 'Payload missing rule_id or rule_ids.',
            ], 120);

            return self::FAILURE;
        }

        $rule = $this->manager->getRule($payload['rule_id']);
        if (! $rule) {
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'error',
                'message' => 'Rule not found.',
            ], 120);

            return self::FAILURE;
        }

        // Verify hashes BEFORE the long apply — same protection as the sync path.
        $entryHashes = $payload['entry_hashes'] ?? [];
        $conflictedEntries = SafeEntrySaver::verifyHashes(is_array($entryHashes) ? $entryHashes : []);

        $userExcluded = $payload['excluded_entry_ids'] ?? [];
        $userExcluded = is_array($userExcluded) ? array_values(array_filter($userExcluded, 'is_string')) : [];

        // Pre-flight: estimate total via preview so the UI has a meaningful "current/total" number.
        $applier = new AutoLinkApplier($this->indexer, $this->manager);
        $applier->setExcludedEntries(array_values(array_unique(
            array_merge(array_keys($conflictedEntries), $userExcluded)
        )));

        $preview = $applier->applyRule($rule, true);
        $totalEstimate = count($preview['affected_entries'] ?? []);

        // Forensic snapshot before any writes — entry_ids carries the
        // preview's would-link set for forensics ("the bulk INTENDED to
        // touch these"), but items=[] starts empty. AutoLinkApplier
        // returns its actual writes via $result['affected_entries'] —
        // we append those after it finishes (no per-item callback yet,
        // so the granularity is end-of-batch rather than per-record;
        // crash mid-apply leaves items empty, which is correct because
        // we don't know which writes landed before the crash).
        $previewEntryIds = [];
        foreach ($preview['affected_entries'] ?? [] as $affected) {
            if (! is_array($affected) || empty($affected['id'])) continue;
            $previewEntryIds[] = $affected['id'];
        }
        $snapshotId = app(BulkSnapshotStore::class)->record(
            kind: 'applyrule',
            entryIds: $previewEntryIds,
            preHashes: is_array($entryHashes) ? array_intersect_key($entryHashes, array_flip($previewEntryIds)) : [],
            summary: [
                'rule_id' => $rule->id,
                'rule_keyword' => $rule->keyword,
                'estimated_links' => $totalEstimate,
            ],
            items: [],
        );

        Cache::put('linkwise:applyrule:status', [
            'phase' => 'running',
            'rule_id' => $rule->id,
            'rule_keyword' => $rule->keyword,
            'current' => 0,
            'total' => $totalEstimate,
            'links_added' => 0,
            'heartbeat' => time(),
        ], 600);

        // Throttle progress writes to ~3/sec to avoid hammering the cache while
        // the applier walks every record.
        $lastProgressWrite = 0;
        $progressCallback = function (int $processed, int $totalRecords, int $linksAdded) use ($rule, $totalEstimate, &$lastProgressWrite) {
            $now = microtime(true);
            if (($now - $lastProgressWrite) < 0.33 && $processed < $totalRecords) {
                return; // skip this tick — the next one will catch up
            }
            $lastProgressWrite = $now;

            Cache::put('linkwise:applyrule:status', [
                'phase' => 'running',
                'rule_id' => $rule->id,
                'rule_keyword' => $rule->keyword,
                'current' => $linksAdded,
                'total' => $totalEstimate,
                'records_processed' => $processed,
                'records_total' => $totalRecords,
                'links_added' => $linksAdded,
                'heartbeat' => time(),
            ], 600);
        };

        // Cancel hook polled inside the applier's record loop. Without this,
        // applyRule runs to completion regardless of clicks on the Cancel
        // button — the post-call cancel branch only fires AFTER all records
        // have been processed, making Cancel feel broken on long rules.
        $shouldCancel = fn () => (bool) Cache::get('linkwise:applyrule:cancel');

        try {
            $result = $applier->applyRule($rule, false, $progressCallback, $shouldCancel);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] ApplyRuleCommand failed: '.$e->getMessage());
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'error',
                'message' => mb_substr($e->getMessage(), 0, 240),
            ], 300);

            return self::FAILURE;
        }

        // Append-on-success: AutoLinkApplier returns the entries it really
        // wrote via $result['affected_entries']. Each one becomes a snapshot
        // item — what didn't write doesn't show up. This is what makes the
        // activity-log table honest about what landed.
        foreach ($result['affected_entries'] ?? [] as $affected) {
            if (! is_array($affected) || empty($affected['id'])) continue;
            app(BulkSnapshotStore::class)->appendWrittenItem($snapshotId, [
                'entry_id' => $affected['id'],
                'anchor_text' => $rule->keyword,
                'url' => $rule->url,
                'sentence_context' => $affected['sentence_context'] ?? '',
            ]);
        }

        if (Cache::get('linkwise:applyrule:cancel')) {
            Cache::forget('linkwise:applyrule:cancel');
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'cancelled',
                'rule_id' => $rule->id,
                'links_added' => $result['links_added'] ?? 0,
            ], 300);
            $this->finalizeIndex($this->affectedIdsFor($rule, $result));

            return self::SUCCESS;
        }

        // Reindex so outboundLinks reflect the inserted links — same as sync controller path.
        if (($result['links_added'] ?? 0) > 0) {
            // Flip to 'indexing' so the banner shows "Finalizing index…"
            // during the rebuild instead of looking stuck at the rule's
            // links_added count.
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'indexing',
                'rule_id' => $rule->id,
                'rule_keyword' => $rule->keyword,
                'current' => $result['links_added'] ?? 0,
                'total' => $totalEstimate,
                'heartbeat' => time(),
            ], 600);
            $this->finalizeIndex($this->affectedIdsFor($rule, $result));
        }

        // Stamp the rule with last-applied metadata so the table can render
        // "Last applied: 2 minutes ago" instead of "Never".
        try {
            $this->manager->updateRule($rule->id, [
                'last_applied_at' => now()->toIso8601String(),
                'last_applied_links_added' => $result['links_added'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] ApplyRuleCommand failed to stamp last-applied: '.$e->getMessage());
        }

        app(BulkSnapshotStore::class)->recordPostHashesForEntries($snapshotId, $previewEntryIds);
        app(BulkSnapshotStore::class)->markCompleted($snapshotId, [
            'phase' => 'done',
            'links_added' => $result['links_added'] ?? 0,
        ]);

        Cache::put('linkwise:applyrule:status', [
            'phase' => 'done',
            'rule_id' => $rule->id,
            'rule_keyword' => $rule->keyword,
            'current' => $result['links_added'] ?? 0,
            'total' => $totalEstimate,
            'links_added' => $result['links_added'] ?? 0,
            'entries_skipped' => $result['entries_skipped'] ?? 0,
            'conflicts' => array_values($conflictedEntries),
            // Root-level heartbeat — see LinkInsertCommand for rationale.
            'heartbeat' => time(),
        ], 300);
        Cache::forget('linkwise:applyrule:payload');

        return self::SUCCESS;
    }

    /**
     * Multi-rule mode: apply a list of rules sequentially within a single
     * heavy job. One JobLock, one banner with nested progress, one cancel
     * point, one terminal toast — analog to UrlChangerApplyCommand's batch
     * pattern. The frontend triggers this once and watches bulkState.
     */
    protected function handleMultiple(array $payload): int
    {
        $ruleIds = array_values(array_filter($payload['rule_ids'], 'is_string'));
        $entryHashes = is_array($payload['entry_hashes'] ?? null) ? $payload['entry_hashes'] : [];
        $userExcluded = $payload['excluded_entry_ids'] ?? [];
        $userExcluded = is_array($userExcluded) ? array_values(array_filter($userExcluded, 'is_string')) : [];

        $totalRules = count($ruleIds);
        $totalLinksAdded = 0;
        $ruleResults = []; // [rule_id => links_added]

        if ($totalRules === 0) {
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'done',
                'total_rules' => 0,
                'total_links_added' => 0,
                'rule_keyword' => '',
                // Root-level heartbeat — see LinkInsertCommand for rationale.
                'heartbeat' => time(),
            ], 300);
            Cache::forget('linkwise:applyrule:payload');

            return self::SUCCESS;
        }

        // Pre-flight hash check across ALL rule scopes — fail-soft (rules just
        // skip the conflicted entries, doesn't abort the whole batch).
        $conflictedEntries = SafeEntrySaver::verifyHashes($entryHashes);
        $excludedAll = array_values(array_unique(
            array_merge(array_keys($conflictedEntries), $userExcluded)
        ));

        // Multi-rule split: instead of ONE meta-snapshot covering the whole
        // batch, we write one snapshot PER rule (kind='applyrule', single-
        // rule shape). This makes every rule individually revertable — the
        // user can revert just rule #3 of a 10-rule "Apply Selected" without
        // touching the others. A shared batch_id ties them back together for
        // future grouping in the activity-log listing (Task #9).
        $batchEntryIds = is_array($entryHashes) ? array_keys($entryHashes) : [];
        $batchId = (string) \Illuminate\Support\Str::uuid();
        // Per-rule snapshot ids, populated as the loop walks rules.
        $perRuleSnapshotIds = [];

        // Polled inside applyRule's record loop so a Cancel click takes effect
        // mid-rule (not just at rule boundaries). Combined with the per-rule
        // boundary check below this gives ~one-record cancellation latency.
        $shouldCancel = fn () => (bool) Cache::get('linkwise:applyrule:cancel');

        // Aggregated set of entry-IDs whose link relationships changed
        // across ALL rules in this batch. Used by finalizeIndex to refresh
        // their suggestion counts so the table stops showing stale
        // pre-apply numbers.
        $allAffectedIds = [];

        foreach ($ruleIds as $idx => $ruleId) {
            // Cancel mid-batch: stops cleanly, reports partial result.
            if (Cache::get('linkwise:applyrule:cancel')) {
                Cache::forget('linkwise:applyrule:cancel');
                Cache::put('linkwise:applyrule:status', [
                    'phase' => 'cancelled',
                    'current_rule_index' => $idx,
                    'total_rules' => $totalRules,
                    'total_links_added' => $totalLinksAdded,
                    'rule_results' => $ruleResults,
                ], 300);
                $this->finalizeIndex(array_keys($allAffectedIds));
                Cache::forget('linkwise:applyrule:payload');

                return self::SUCCESS;
            }

            $rule = $this->manager->getRule($ruleId);
            if (! $rule || ! $rule->active) {
                continue;
            }

            $applier = new AutoLinkApplier($this->indexer, $this->manager);
            $applier->setExcludedEntries($excludedAll);

            try {
                $preview = $applier->applyRule($rule, true);
                $totalEstimate = count($preview['affected_entries'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] ApplyRuleCommand multi preview failed for '.$rule->keyword.': '.$e->getMessage());
                continue;
            }

            // Per-rule snapshot. Single-rule shape so revertHelper handles
            // it without any multi-rule special-case (we drop the multi-rule
            // block separately). batch_id + batch_index let the listing
            // collapse them back into a "Apply Selected" grouping later.
            $rulePreviewEntryIds = [];
            foreach ($preview['affected_entries'] ?? [] as $aff) {
                if (is_array($aff) && ! empty($aff['id'])) $rulePreviewEntryIds[] = $aff['id'];
            }
            $rulePreHashes = is_array($entryHashes)
                ? array_intersect_key($entryHashes, array_flip($rulePreviewEntryIds))
                : [];
            $ruleSnapshotId = app(BulkSnapshotStore::class)->record(
                kind: 'applyrule',
                entryIds: $rulePreviewEntryIds,
                preHashes: $rulePreHashes,
                summary: [
                    'rule_id' => $rule->id,
                    'rule_keyword' => $rule->keyword,
                    'estimated_links' => $totalEstimate,
                    // Batch metadata — same UUID across all rules in this
                    // "Apply Selected" run; the listing can collapse rows
                    // sharing a batch_id.
                    'batch_id' => $batchId,
                    'batch_index' => $idx + 1,
                    'batch_total' => $totalRules,
                ],
                items: [],
            );
            $perRuleSnapshotIds[$rule->id] = $ruleSnapshotId;

            // Initial status for this rule — banner shows "rule X of Y".
            Cache::put('linkwise:applyrule:status', [
                'phase' => 'running',
                'rule_id' => $rule->id,
                'rule_keyword' => $rule->keyword,
                'current_rule_index' => $idx + 1,
                'total_rules' => $totalRules,
                'current' => 0,
                'total' => $totalEstimate,
                'links_added' => 0,
                'total_links_added' => $totalLinksAdded,
                'heartbeat' => time(),
            ], 600);

            $lastProgressWrite = 0;
            $progressCallback = function (int $processed, int $totalRecords, int $linksAdded) use ($rule, $idx, $totalRules, $totalEstimate, $totalLinksAdded, &$lastProgressWrite) {
                $now = microtime(true);
                if (($now - $lastProgressWrite) < 0.33 && $processed < $totalRecords) {
                    return;
                }
                $lastProgressWrite = $now;
                Cache::put('linkwise:applyrule:status', [
                    'phase' => 'running',
                    'rule_id' => $rule->id,
                    'rule_keyword' => $rule->keyword,
                    'current_rule_index' => $idx + 1,
                    'total_rules' => $totalRules,
                    'current' => $linksAdded,
                    'total' => $totalEstimate,
                    'records_processed' => $processed,
                    'records_total' => $totalRecords,
                    'links_added' => $linksAdded,
                    'total_links_added' => $totalLinksAdded + $linksAdded,
                    'heartbeat' => time(),
                ], 600);
            };

            try {
                $result = $applier->applyRule($rule, false, $progressCallback, $shouldCancel);
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] ApplyRuleCommand multi apply failed for '.$rule->keyword.': '.$e->getMessage());
                continue;
            }

            $linksAdded = $result['links_added'] ?? 0;
            $totalLinksAdded += $linksAdded;
            $ruleResults[$rule->id] = $linksAdded;

            // Append-on-success — items into THIS rule's snapshot, single-
            // rule shape (no rule_id needed inside the item; the snapshot's
            // summary carries it). Entries that got skipped never enter
            // $result['affected_entries'].
            $writtenIds = [];
            foreach ($result['affected_entries'] ?? [] as $affected) {
                if (! is_array($affected) || empty($affected['id'])) continue;
                $writtenIds[] = $affected['id'];
                app(BulkSnapshotStore::class)->appendWrittenItem($ruleSnapshotId, [
                    'entry_id' => $affected['id'],
                    'anchor_text' => $rule->keyword,
                    'url' => $rule->url,
                    'sentence_context' => $affected['sentence_context'] ?? '',
                ]);
            }

            // Accumulate this rule's affected entries (sources + target).
            foreach ($this->affectedIdsFor($rule, $result) as $id) {
                $allAffectedIds[$id] = true;
            }

            // Cancelled mid-rule: leave the per-rule snapshot WITHOUT
            // markCompleted — the activity-log listing will show it as
            // "in progress" (which is honest, the rule didn't finish).
            // The next iteration's flag check writes the batch's cancel
            // status, so we just break here.
            if (! empty($result['cancelled'])) {
                break;
            }

            // Per-rule post-hashes + finalize. recordPostHashesForEntries
            // captures the live hashes of the entries we just wrote, so a
            // future revert can detect whether the user has edited any of
            // them since this rule ran.
            app(BulkSnapshotStore::class)->recordPostHashesForEntries(
                $ruleSnapshotId,
                $writtenIds ?: $rulePreviewEntryIds,
            );
            app(BulkSnapshotStore::class)->markCompleted($ruleSnapshotId, [
                'phase' => 'done',
                'links_added' => $linksAdded,
            ]);

            // Stamp last-applied per rule (same semantics as single-rule path).
            try {
                $this->manager->updateRule($rule->id, [
                    'last_applied_at' => now()->toIso8601String(),
                    'last_applied_links_added' => $linksAdded,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] ApplyRuleCommand multi stamp failed: '.$e->getMessage());
            }
        }

        // Flip to 'indexing' before finalizeIndex so the multi-rule banner
        // shows "Finalizing index…" during the rebuild — without this it
        // sat stuck at "Applied N of N rules" for 1-3min on large sites.
        Cache::put('linkwise:applyrule:status', [
            'phase' => 'indexing',
            'total_rules' => $totalRules,
            'total_links_added' => $totalLinksAdded,
            'current_rule_index' => $totalRules,
            'heartbeat' => time(),
        ], 600);

        $this->finalizeIndex(array_keys($allAffectedIds));

        // Per-rule snapshots already had recordPostHashes + markCompleted
        // called inside the loop (per success). No outer snapshot to close
        // out — the activity log shows N independent applyrule snapshots.

        Cache::put('linkwise:applyrule:status', [
            'phase' => 'done',
            'total_rules' => $totalRules,
            'total_links_added' => $totalLinksAdded,
            'rule_results' => $ruleResults,
            'conflicts' => array_values($conflictedEntries),
            // Banner shows "Applied X rules" via the multi-rule label.
            'rule_keyword' => '',
            // Root-level heartbeat — see LinkInsertCommand for rationale.
            'heartbeat' => time(),
        ], 300);
        Cache::forget('linkwise:applyrule:payload');

        return self::SUCCESS;
    }

    /**
     * Extract the entry-IDs whose link relationships changed when this
     * rule was applied: every entry that received the link (from
     * affected_entries) plus the rule's target if it's an internal
     * statamic://entry::ID reference.
     *
     * @param  array  $result  Output of AutoLinkApplier::applyRule(false)
     * @return list<string>
     */
    protected function affectedIdsFor($rule, array $result): array
    {
        $ids = [];
        foreach ($result['affected_entries'] ?? [] as $entry) {
            $id = $entry['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }
        if ($rule->targetEntryId) {
            $ids[$rule->targetEntryId] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param  list<string>  $affectedEntryIds  Entries whose link
     *   relationships actually changed during this run. Their suggestion
     *   counts get recomputed AFTER the index rebuild — buildIndex's
     *   default preserveSuggestionCounts copies OLD counts forward, so
     *   without targeted refresh the table keeps showing stale numbers
     *   for entries the rule just linked.
     */
    protected function finalizeIndex(array $affectedEntryIds = []): void
    {
        try {
            $previousCount = count($this->indexer->load());
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();
            if (count($records) === 0 && $previousCount > 0) {
                Log::warning(
                    '[Linkwise] ApplyRuleCommand: refusing to save empty index (previous had '.$previousCount.' records)',
                );

                return;
            }
            $this->indexer->save($records);
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] ApplyRuleCommand finalizeIndex failed: '.$e->getMessage());

            return;
        }

        $cap = 20;
        if (! empty($affectedEntryIds) && count($affectedEntryIds) <= $cap) {
            try {
                $this->indexer->computeSuggestionCountsForEntries($affectedEntryIds);
            } catch (\Throwable $e) {
                Log::warning(
                    '[Linkwise] ApplyRuleCommand suggestion-count refresh failed: '.$e->getMessage(),
                );
            }
        } elseif (! empty($affectedEntryIds)) {
            Log::info(
                '[Linkwise] ApplyRuleCommand skipped suggestion-count refresh — '
                .count($affectedEntryIds).' affected entries exceeds cap of '.$cap
                .'. Counts will refresh at the next Scan Content.',
            );
        }
    }
}
