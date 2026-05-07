<?php

namespace Arturrossbach\Linkwise\Support;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Records a forensic snapshot before each write-bulk runs.
 *
 * Linkwise's "ups, my colleague applied a wrong rule on 80 entries" recovery
 * path. The snapshot is read-only metadata — entry IDs, pre-bulk SafeEntrySaver
 * hashes, who started it, when, what kind. NOT a content backup: restore is
 * the user's job (git / Statamic Revisions / hosting backup), Linkwise just
 * gives them an exact list of what to restore.
 *
 * Driver-agnostic: stores in Linkwise's own `storage/linkwise/bulk-snapshots/`
 * directory, talks to Statamic only through the Entry facade. Works the same
 * with Stache (flat-file) or Eloquent (DB-backed) entries.
 *
 * Activity-Log UI in the CP reads from this store and renders the list +
 * detail drawer. See DashboardController::activity for the read path.
 */
class BulkSnapshotStore
{
    protected string $storagePath;

    /**
     * Snapshots older than this are auto-cleaned on the next write. Keeps the
     * directory bounded — typical user runs a few bulks per week, 30 days is
     * plenty for "I did something yesterday and need to undo it" recovery.
     */
    protected const RETENTION_DAYS = 30;

    /**
     * Hard cap on entries listed per snapshot. A 5000-entry URL-changer batch
     * would otherwise produce a multi-MB JSON file. The list is for forensic
     * reference, not exhaustive — if a user hit the cap they know it was a
     * very large operation.
     */
    protected const MAX_ENTRIES_PER_SNAPSHOT = 1000;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise/bulk-snapshots');
    }

    /**
     * Record a snapshot before a bulk runs.
     *
     * @param  string  $kind  One of the JobLock kind keys (applyrule, urlchanger,
     *   bulkunlink, detailunlink, inboundinsert, outboundinsert).
     * @param  list<string>  $entryIds  Entries this bulk plans to touch.
     * @param  array<string, string>  $preHashes  [entryId => SafeEntrySaver hash before].
     * @param  array<string, mixed>  $summary  Kind-specific metadata (rule keyword,
     *   search term, source mode, etc.) — surfaced verbatim in the activity-log
     *   detail view, so it should be human-readable.
     * @param  list<array<string, mixed>>  $items  Per-item operation data so the
     *   activity-log drawer can show "anchor 'vue.js' inserted in entry X" and
     *   the deep-link button can route to URL Changer with the right search.
     *   Shape varies by kind:
     *     - applyrule:        [{entry_id, anchor_text, url}]
     *     - inboundinsert:    [{entry_id, source_entry_id, target_entry_id, anchor_text}]
     *     - outboundinsert:   [{entry_id, source_entry_id, target_entry_id, anchor_text}]
     *     - detailunlink:     [{entry_id, matched_url, anchor_text}]
     *     - bulkunlink:       [{entry_id, matched_url}]
     *     - urlchanger:       [{entry_id, matched_url, new_url}]
     * @return string  The snapshot id (also used as filename without .json).
     */
    public function record(
        string $kind,
        array $entryIds,
        array $preHashes = [],
        array $summary = [],
        array $items = [],
    ): string {
        $this->cleanupStale();
        $this->ensureDirectory();

        $id = $this->newId();
        $entryIds = array_values(array_unique(array_filter(
            $entryIds,
            fn ($v) => is_string($v) && $v !== '',
        )));

        // Cap to keep the file size sane on huge bulks. The "trimmed" flag
        // tells the activity-log UI to show "N of M entries listed".
        $trimmed = false;
        $totalCount = count($entryIds);
        if ($totalCount > self::MAX_ENTRIES_PER_SNAPSHOT) {
            $entryIds = array_slice($entryIds, 0, self::MAX_ENTRIES_PER_SNAPSHOT);
            $trimmed = true;
        }

        // Cap items to the same MAX so a 5000-replacement URL changer batch
        // doesn't blow up the file. Activity-log surfaces these for forensics
        // — we don't need to round-trip every single one.
        $itemsTrimmed = false;
        $itemCountTotal = count($items);
        if ($itemCountTotal > self::MAX_ENTRIES_PER_SNAPSHOT) {
            $items = array_slice($items, 0, self::MAX_ENTRIES_PER_SNAPSHOT);
            $itemsTrimmed = true;
        }
        // Filter out non-array items defensively (caller should send arrays).
        $items = array_values(array_filter($items, 'is_array'));

        $data = [
            'id' => $id,
            'kind' => $kind,
            'started_by' => $this->currentUserName(),
            'started_by_id' => $this->currentUserId(),
            'started_at' => now()->toIso8601String(),
            'entry_ids' => $entryIds,
            'pre_hashes' => $preHashes,
            'summary' => $summary,
            'items' => $items,
            'entry_count_total' => $totalCount,
            'entries_trimmed' => $trimmed,
            'item_count_total' => $itemCountTotal,
            'items_trimmed' => $itemsTrimmed,
        ];

        try {
            file_put_contents(
                $this->pathFor($id),
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            // Recording is best-effort — never let a snapshot-write failure
            // break the actual bulk the user wanted to run.
            Log::warning('[Linkwise] BulkSnapshotStore: record failed — '.$e->getMessage());
        }

        return $id;
    }

    /**
     * Most-recent snapshots first. Bounded by $limit (default 50, the
     * activity-log table doesn't paginate yet).
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 50): array
    {
        if (! is_dir($this->storagePath)) {
            return [];
        }

        $files = glob($this->storagePath.'/*.json') ?: [];
        // Sort by mtime desc — newest first.
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $files = array_slice($files, 0, $limit);

        $records = [];
        foreach ($files as $file) {
            $data = $this->readFile($file);
            if ($data === null) {
                continue;
            }
            $records[] = $data;
        }

        return $records;
    }

    /**
     * Single snapshot by id. Returns null if missing or unparseable.
     */
    public function get(string $id): ?array
    {
        $path = $this->pathFor($id);
        if (! file_exists($path)) {
            return null;
        }

        return $this->readFile($path);
    }

    /**
     * Mark a snapshot as reverted. Called by the activity-log Revert flow
     * once the inverse bulk has finished. The activity-log UI uses these
     * fields to show a "[Reverted]" badge and to disable the Revert button
     * (avoids double-revert which would re-apply the original op).
     */
    public function markReverted(string $id, ?string $revertedBy = null): void
    {
        $path = $this->pathFor($id);
        if (! file_exists($path)) {
            return;
        }
        $data = $this->readFile($path);
        if ($data === null) {
            return;
        }
        $data['reverted_at'] = now()->toIso8601String();
        if ($revertedBy !== null) {
            $data['reverted_by'] = $revertedBy;
        }
        try {
            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] BulkSnapshotStore::markReverted failed — '.$e->getMessage());
        }
    }

    /**
     * Compare a snapshot's pre-hashes with current entry hashes. Returns a
     * map of entry-id → status for every entry the bulk touched:
     *
     *   'unchanged'  — entry hash still matches pre-bulk (the bulk's effects
     *                  are still in place, restore is meaningful)
     *   'modified'   — entry was edited after the bulk (restore would also
     *                  wipe those edits)
     *   'deleted'    — entry no longer exists (was deleted post-bulk)
     *   'unknown'    — pre-hash wasn't recorded (nothing to compare against)
     *
     * @return array<string, string>
     */
    public function compareToCurrent(array $snapshot): array
    {
        $statuses = [];
        $preHashes = $snapshot['pre_hashes'] ?? [];

        foreach ($snapshot['entry_ids'] ?? [] as $entryId) {
            $expected = $preHashes[$entryId] ?? null;
            if ($expected === null) {
                $statuses[$entryId] = 'unknown';
                continue;
            }
            $entry = EntryFacade::find($entryId);
            if (! $entry) {
                $statuses[$entryId] = 'deleted';
                continue;
            }
            $current = SafeEntrySaver::hash($entry);
            $statuses[$entryId] = ($current === $expected) ? 'unchanged' : 'modified';
        }

        return $statuses;
    }

    /**
     * Delete snapshots older than RETENTION_DAYS. Called on every record()
     * — cheap (one filemtime check per file).
     */
    protected function cleanupStale(): void
    {
        if (! is_dir($this->storagePath)) {
            return;
        }

        $cutoff = time() - (self::RETENTION_DAYS * 86400);
        foreach (glob($this->storagePath.'/*.json') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    protected function ensureDirectory(): void
    {
        if (! is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    protected function pathFor(string $id): string
    {
        return $this->storagePath.'/'.$id.'.json';
    }

    protected function newId(): string
    {
        // Sortable + unique — date prefix means glob() + filemtime sorting
        // matches the lexicographic id ordering, useful for debugging.
        return date('Ymd-His').'-'.bin2hex(random_bytes(4));
    }

    protected function readFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    protected function currentUserId(): ?string
    {
        try {
            return auth()->user()?->id();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function currentUserName(): ?string
    {
        try {
            $user = auth()->user();
            if (! $user) {
                return null;
            }
            // Statamic users have name() / email() helpers
            if (method_exists($user, 'name')) {
                return $user->name();
            }

            return $user->email() ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
