<?php

namespace Arturrossbach\Linkwise\Http\Controllers\AutoLink;

use Arturrossbach\Linkwise\AutoLink\AutoLinkApplier;
use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\JobLock;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Synchronous Auto-Link Apply endpoint.
 *
 * Extracted from {@see \Arturrossbach\Linkwise\Http\Controllers\AutoLinkController}
 * during REV-AL-01 (Sprint 4 Part 4). Owns the only mini-Bulk-Write-Path
 * outside of the bulk Commands — the one with concrete drift-risk vs
 * {@see \Arturrossbach\Linkwise\Commands\LinkInsertCommand} et al.
 *
 * Async apply, CRUD, CSV, and bulk-toggle remain on the original controller
 * — their empirical bug-density (0 in 6 months) did not justify extraction.
 *
 * Behaviour pinned by {@see \Arturrossbach\Linkwise\Tests\Feature\AutoLink\AutoLinkApplySyncTest}.
 */
class AutoLinkApplySyncController extends CpController
{
    public function __construct(
        protected AutoLinkManager $manager,
        protected EntryIndexer $indexer,
    ) {}

    public function apply(Request $request, string $id): JsonResponse
    {
        $rule = $this->manager->getRule($id);

        if (! $rule) {
            return response()->json(['error' => 'Rule not found'], 404);
        }

        $preview = $request->boolean('preview', false);

        // Concurrency guard: refuse non-preview apply when ANY other heavy job
        // is running. Preview is read-only (no entry writes) so it's exempt.
        // Without this, the sync per-rule apply could race with a Scan / URL-
        // Changer / Bulk-Unlink writer on the same entry file + index.
        if (! $preview) {
            if ($active = JobLock::activeJob('applyrule')) {
                return response()->json(JobLock::busyResponseData($active), 409);
            }
        }
        $conflictedEntries = ! $preview
            ? SafeEntrySaver::verifyHashes($request->input('entry_hashes', []))
            : [];

        // User-picked skip list from the Preview modal ("Include" checkbox unchecked).
        $userExcluded = $request->input('excluded_entry_ids', []);
        $userExcluded = is_array($userExcluded) ? array_values(array_filter($userExcluded, 'is_string')) : [];

        $applier = new AutoLinkApplier($this->indexer, $this->manager);
        $applier->setExcludedEntries(array_values(array_unique(
            array_merge(array_keys($conflictedEntries), $userExcluded)
        )));

        // Forensic snapshot for non-preview applies. We dry-run a preview first
        // to learn which entries the apply would touch — costs a bit, but the
        // alternative (snapshotting AFTER the apply) misses entries on partial
        // failures, defeating the purpose of pre-write forensics.
        if (! $preview) {
            $previewApplier = new AutoLinkApplier($this->indexer, $this->manager);
            $previewApplier->setExcludedEntries(array_values(array_unique(
                array_merge(array_keys($conflictedEntries), $userExcluded)
            )));
            $previewForSnapshot = $previewApplier->applyRule($rule, true);
            $snapshotEntryIds = [];
            foreach ($previewForSnapshot['affected_entries'] ?? [] as $affected) {
                if (! is_array($affected) || empty($affected['id'])) continue;
                $snapshotEntryIds[] = $affected['id'];
            }
            $hashes = $request->input('entry_hashes', []);
            // items=[] start; we append per-entry below after the apply
            // returns its actual writes (append-on-success pattern).
            $snapshotId = app(BulkSnapshotStore::class)->record(
                kind: 'applyrule',
                entryIds: $snapshotEntryIds,
                preHashes: is_array($hashes) ? array_intersect_key($hashes, array_flip($snapshotEntryIds)) : [],
                summary: [
                    'rule_id' => $rule->id,
                    'rule_keyword' => $rule->keyword,
                    'caller' => 'sync',
                ],
                items: [],
            );
        } else {
            $snapshotId = null;
        }

        $result = $applier->applyRule($rule, $preview);

        // Post-bulk hashes + append-on-success items for the activity-log
        // (apply path only — preview doesn't write). Skipped when no
        // snapshot was taken.
        if (! $preview && $snapshotId !== null && ! empty($snapshotEntryIds)) {
            $writtenIds = [];
            foreach ($result['affected_entries'] ?? [] as $affected) {
                if (! is_array($affected) || empty($affected['id'])) continue;
                $writtenIds[] = $affected['id'];
                app(BulkSnapshotStore::class)->appendWrittenItem($snapshotId, [
                    'entry_id' => $affected['id'],
                    'anchor_text' => $rule->keyword,
                    'url' => $rule->url,
                    'sentence_context' => $affected['sentence_context'] ?? '',
                ]);
            }
            app(BulkSnapshotStore::class)->recordPostHashesForEntries($snapshotId, $writtenIds ?: $snapshotEntryIds);
            app(BulkSnapshotStore::class)->markCompleted($snapshotId, [
                'phase' => 'done',
                'links_added' => $result['links_added'] ?? 0,
            ]);
        }

        if (! empty($conflictedEntries)) {
            $result['conflicts'] = array_values($conflictedEntries);
            $result['conflict_message'] = count($conflictedEntries).' entry/entries were modified by another user and skipped.';
        }

        // Preview returns fresh hashes so the next Apply sees the entries' current state.
        // Without this, reopening the modal after a 409 sends the page-load hash again
        // and the user can't recover without a full reload.
        if ($preview) {
            $hashes = [];
            foreach ($result['affected_entries'] ?? [] as $a) {
                $eid = $a['id'] ?? null;
                if (! $eid || isset($hashes[$eid])) {
                    continue;
                }
                $e = \Statamic\Facades\Entry::find($eid);
                if ($e) {
                    $hashes[$eid] = SafeEntrySaver::hash($e);
                }
            }
            $result['entry_hashes'] = $hashes;
        }

        if (! $preview && ($result['links_added'] ?? 0) > 0) {
            $this->indexer->clearCache();
            $records = $this->indexer->buildIndex();
            $this->indexer->save($records);

            // Return fresh hashes so frontend can update for sequential rule applies
            $result['updated_hashes'] = $this->computeCurrentHashes($request->input('entry_hashes', []));
        }

        // Stamp the rule with last-applied metadata after a real (non-preview) run.
        // Done outside the rebuild branch so a 0-links-added run still records that
        // the rule was attempted — useful for users to see "ran 5 minutes ago,
        // nothing new to link" instead of "Never".
        if (! $preview) {
            $this->manager->updateRule($rule->id, [
                'last_applied_at' => now()->toIso8601String(),
                'last_applied_links_added' => $result['links_added'] ?? 0,
            ]);
            $updatedRule = $this->manager->getRule($rule->id);
            if ($updatedRule) {
                $result['rule'] = $updatedRule->toArray();
            }
        }

        return response()->json($result);
    }

    /**
     * Compute fresh hashes for entries that had hashes sent in the request.
     */
    protected function computeCurrentHashes(array $originalHashes): array
    {
        $hashes = [];
        foreach (array_keys($originalHashes) as $entryId) {
            $entry = \Statamic\Facades\Entry::find($entryId);
            if ($entry) {
                $hashes[$entryId] = SafeEntrySaver::hash($entry);
            }
        }

        return $hashes;
    }
}
