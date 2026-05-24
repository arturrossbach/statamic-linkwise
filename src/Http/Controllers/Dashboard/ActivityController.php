<?php

namespace Arturrossbach\Linkwise\Http\Controllers\Dashboard;

use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Activity-log JSON API: snapshot detail + mark-reverted endpoints.
 *
 * Extracted from {@see \Arturrossbach\Linkwise\Http\Controllers\DashboardController}
 * during REV-DR-01 Phase B PR 3. Cluster scope: snapshot enrichment (entries,
 * resolved target titles + edit-URLs, deep-link to URL Changer, reverted_by
 * user resolution) and the revert-marker endpoint that flips the [Reverted]
 * badge on completed snapshots.
 *
 * Constructor is intentionally empty — both methods resolve
 * {@see BulkSnapshotStore} via `app()` inline (matches the DC source pattern
 * and avoids forcing the test stack to bind it).
 *
 * Behaviour pinned by {@see \Arturrossbach\Linkwise\Tests\Feature\Dashboard\ActivityDetailTest}
 * (HTTP, 19 cases) and {@see \Arturrossbach\Linkwise\Tests\Unit\Dashboard\ActivityDeepLinkSearchTest}
 * (Reflection on the kind-switch helper, 8 cases).
 */
class ActivityController extends CpController
{
    /**
     * JSON detail for a single snapshot — entry list + per-entry status
     * (unchanged / modified / deleted / unknown). Used by the activity-log
     * detail drawer to render "5 of 80 entries were edited since the bulk".
     */
    public function activityDetail(Request $request, string $id): JsonResponse
    {
        $store = app(BulkSnapshotStore::class);
        $snap = $store->get($id);
        if (! $snap) {
            return response()->json(['error' => 'Snapshot not found'], 404);
        }

        $statuses = $store->compareToCurrent($snap);

        // Build a per-entry index of items so the drawer can show
        // "anchor 'vue.js' inserted" / "removed link to /old-url" etc.
        // Most kinds key items by entry_id; link-insert items use
        // source_entry_id (the entry being modified). Multi-rule applyrule
        // items have rule_id but no entry_id — those are listed separately.
        $itemsByEntry = [];
        foreach ($snap['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = $item['entry_id'] ?? $item['source_entry_id'] ?? null;
            if ($key === null) {
                continue;
            }
            $itemsByEntry[$key][] = $item;
        }

        // Pre-compute a snapshot-recorded title lookup so deleted entries can
        // still render with their write-time title instead of a raw UUID.
        // LinkInsertCommand (post-2026-05-24) stores source_entry_title +
        // target_entry_title on every appendWrittenItem; older snapshots
        // pre-date this and fall through to the legacy Entry::find path.
        $snapshotTitlesById = [];
        foreach ($snap['items'] ?? [] as $item) {
            if (! is_array($item)) continue;
            if (! empty($item['source_entry_id']) && ! empty($item['source_entry_title'])) {
                $snapshotTitlesById[$item['source_entry_id']] = $item['source_entry_title'];
            }
            if (! empty($item['target_entry_id']) && ! empty($item['target_entry_title'])) {
                $snapshotTitlesById[$item['target_entry_id']] = $item['target_entry_title'];
            }
        }

        $entries = [];
        foreach ($snap['entry_ids'] ?? [] as $entryId) {
            $editUrl = null;
            $collection = null;
            $isDeleted = false;
            try {
                $entry = Entry::find($entryId);
                if ($entry) {
                    $title = $entry->get('title') ?? $entryId;
                    $collection = $entry->collectionHandle();
                    $editUrl = cp_route('collections.entries.edit', [$collection, $entryId]);
                } else {
                    // Entry no longer exists. Prefer the snapshot-recorded
                    // title (write-time capture) over the bare UUID, which
                    // was the legacy fallback and looked like a bug to users.
                    $isDeleted = true;
                    $title = $snapshotTitlesById[$entryId] ?? '(deleted entry)';
                }
            } catch (\Throwable) {
                // Best-effort — fall back to ID-only display.
                $title = $snapshotTitlesById[$entryId] ?? '(deleted entry)';
                $isDeleted = true;
            }
            // Enrich items with target-entry titles where the URL points to
            // a Statamic entry. The drawer otherwise shows opaque "entry: abc123de…"
            // for internal links — useless to a user who can't read UUIDs at
            // a glance. We resolve target_entry_id explicitly (link-insert items)
            // AND any *_url field that uses the statamic://entry::UUID scheme
            // (apply-rule, detail-unlink, url-changer items).
            $resolvedItems = [];
            foreach ($itemsByEntry[$entryId] ?? [] as $item) {
                $resolved = $item;
                foreach (['url', 'matched_url', 'new_url', 'target_entry_id'] as $field) {
                    if (empty($item[$field])) continue;
                    $value = (string) $item[$field];
                    $targetId = null;
                    if ($field === 'target_entry_id') {
                        $targetId = $value;
                    } elseif (preg_match('#^statamic://entry::([0-9a-f-]+)$#i', $value, $m)) {
                        $targetId = $m[1];
                    }
                    if ($targetId !== null) {
                        try {
                            $targetEntry = Entry::find($targetId);
                            if ($targetEntry) {
                                $resolved[$field.'_title'] = $targetEntry->get('title') ?? $targetId;
                                $resolved[$field.'_edit_url'] = cp_route(
                                    'collections.entries.edit',
                                    [$targetEntry->collectionHandle(), $targetId],
                                );
                            }
                        } catch (\Throwable) {
                            // Best-effort — UI falls back to truncated id.
                        }
                    }
                }
                $resolvedItems[] = $resolved;
            }

            $entries[] = [
                'id' => $entryId,
                'title' => $title,
                'collection' => $collection,
                'edit_url' => $editUrl,
                'is_deleted' => $isDeleted,
                'status' => $statuses[$entryId] ?? 'unknown',
                'items' => $resolvedItems,
            ];
        }

        // Enrich the top-level snapshot.items too — the drawer's summary
        // header (operationSummary) reads them directly to show a uniform
        // "Inserted 'X' → <target>" line. Without this, that line would
        // dump the raw 'entry: abc12345…' UUID string. Same enrichment as
        // we do per-entry above; transient, doesn't touch the snapshot file.
        if (! empty($snap['items']) && is_array($snap['items'])) {
            $snap['items'] = array_map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }
                $resolved = $item;
                foreach (['url', 'matched_url', 'new_url', 'target_entry_id'] as $field) {
                    if (empty($item[$field])) continue;
                    $value = (string) $item[$field];
                    $targetId = null;
                    if ($field === 'target_entry_id') {
                        $targetId = $value;
                    } elseif (preg_match('#^statamic://entry::([0-9a-f-]+)$#i', $value, $m)) {
                        $targetId = $m[1];
                    }
                    if ($targetId !== null) {
                        try {
                            $targetEntry = Entry::find($targetId);
                            if ($targetEntry) {
                                $resolved[$field.'_title'] = $targetEntry->get('title') ?? $targetId;
                                $resolved[$field.'_edit_url'] = cp_route(
                                    'collections.entries.edit',
                                    [$targetEntry->collectionHandle(), $targetId],
                                );
                            }
                        } catch (\Throwable) {
                            // Best-effort.
                        }
                    }
                }
                return $resolved;
            }, $snap['items']);
        }

        // Enrich revert_skipped records with edit_url so the drawer's
        // skipped-entries table can render Entry as a clickable link.
        // Best-effort — buildSkipRecord persisted entry_id + entry_title +
        // modified_by + modified_at; we resolve the entry here to add the
        // edit_url. Deleted entries (lookup miss) keep entry_title as-is
        // and edit_url stays absent.
        if (! empty($snap['revert_skipped']) && is_array($snap['revert_skipped'])) {
            $snap['revert_skipped'] = array_map(function ($row) {
                if (! is_array($row) || empty($row['entry_id'])) return $row;
                try {
                    $e = Entry::find($row['entry_id']);
                    if ($e) {
                        $row['edit_url'] = cp_route(
                            'collections.entries.edit',
                            [$e->collectionHandle(), $row['entry_id']],
                        );
                        $row['collection'] = $e->collectionHandle();
                    }
                } catch (\Throwable) {
                    // Deleted entry — leave the record as-is.
                }
                return $row;
            }, $snap['revert_skipped']);
        }

        // Same enrichment for bulk_skipped (entries the bulk wanted to touch
        // but couldn't — anchor not found, hash conflict, etc.). Renders in
        // its own table in the drawer. Bug 2026-05-11: non-revert bulks
        // previously had no per-entry skip visibility, only an aggregate
        // skipped count in the toast/banner.
        if (! empty($snap['bulk_skipped']) && is_array($snap['bulk_skipped'])) {
            $snap['bulk_skipped'] = array_map(function ($row) {
                if (! is_array($row) || empty($row['entry_id'])) return $row;
                try {
                    $e = Entry::find($row['entry_id']);
                    if ($e) {
                        $row['edit_url'] = cp_route(
                            'collections.entries.edit',
                            [$e->collectionHandle(), $row['entry_id']],
                        );
                        $row['collection'] = $e->collectionHandle();
                    }
                } catch (\Throwable) {
                    // Deleted entry — leave the record as-is.
                }
                return $row;
            }, $snap['bulk_skipped']);
        }

        // Compute a deep-link to URL Changer search if we have a meaningful
        // search term. Used by the drawer's "Find these in URL Changer" button.
        $deepLinkSearch = $this->deepLinkSearchFor($snap);

        // Resolve the reverted_by snapshot id → its started_by user name, so
        // the drawer can show "Already reverted on DATE by NAME" instead of
        // an opaque "snap-id-XYZ".
        $revertedByUser = null;
        if (! empty($snap['reverted_by'])) {
            $revertedSnap = $store->get($snap['reverted_by']);
            $revertedByUser = $revertedSnap['started_by'] ?? null;
        }

        // Resolve the reverts pointer in this snapshot's summary → return a
        // compact "what we reverted" descriptor so the drawer can render
        // "↶ Reverts Apply Rule 'X' from <date> by <user>" up top.
        $revertedFrom = null;
        $revertsId = $snap['summary']['reverts'] ?? null;
        if (! empty($revertsId)) {
            $original = $store->get($revertsId);
            if ($original) {
                $revertedFrom = [
                    'id' => $original['id'] ?? $revertsId,
                    'kind' => $original['kind'] ?? 'unknown',
                    'started_at' => $original['started_at'] ?? null,
                    'started_by' => $original['started_by'] ?? null,
                    'summary' => $original['summary'] ?? [],
                ];
            }
        }

        return response()->json([
            'snapshot' => $snap,
            'entries' => $entries,
            'deep_link_url_changer' => $deepLinkSearch
                ? cp_route('linkwise.urlchanger').'?search='.urlencode($deepLinkSearch)
                : null,
            'reverted_by_user' => $revertedByUser,
            'reverted_from' => $revertedFrom,
        ]);
    }

    /**
     * Mark a snapshot as reverted. Called by the activity-log Revert flow
     * once the inverse bulk has been dispatched. The server doesn't verify
     * the new bulk's success here (the activity-log will pick up the result
     * status of the new snapshot anyway) — this just flips the original's
     * "[Reverted]" badge so the Revert button hides on subsequent reads.
     */
    public function markActivityReverted(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reverted_by' => 'nullable|string|max:128',
        ]);
        $store = app(BulkSnapshotStore::class);

        // Defense-in-depth — the frontend already disables Revert for in-flight
        // snapshots, but a malicious / racing client could still POST here.
        // Refuse if the original isn't done.
        $snap = $store->get($id);
        if (! $snap) {
            return response()->json(['error' => 'snapshot_not_found'], 404);
        }
        // Legacy snapshots without completed_at are treated as completed
        // (they're old). Explicit null = still running.
        if (array_key_exists('completed_at', $snap) && $snap['completed_at'] === null) {
            return response()->json([
                'error' => 'in_progress',
                'message' => 'Cannot revert: the original bulk is still running.',
            ], 409);
        }

        $store->markReverted($id, $request->input('reverted_by'));

        return response()->json(['success' => true]);
    }

    /**
     * Pick a sensible URL Changer search term based on the snapshot kind.
     * Lets the user jump from the activity-log straight into a tab where
     * they can manually unlink/re-link the same set of links.
     *
     * Returns null when no meaningful term exists (e.g. detailunlink doesn't
     * have a single common URL across items).
     */
    protected function deepLinkSearchFor(array $snap): ?string
    {
        $kind = $snap['kind'] ?? '';
        $items = $snap['items'] ?? [];
        $summary = $snap['summary'] ?? [];

        if ($kind === 'applyrule') {
            // Single-rule: the rule's URL. Multi-rule: skip (different URLs).
            if (($summary['mode'] ?? '') === 'multi-rule') {
                return null;
            }
            $first = $items[0] ?? null;
            return is_array($first) && ! empty($first['url']) ? $first['url'] : null;
        }
        if ($kind === 'urlchanger') {
            return $summary['search'] ?? null;
        }
        if ($kind === 'inboundinsert' || $kind === 'outboundinsert') {
            // The target entry is the same across all items in inbound mode;
            // for outbound the source is shared. Either way, no single URL.
            return null;
        }

        return null;
    }
}
