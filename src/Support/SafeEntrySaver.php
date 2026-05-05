<?php

namespace Arturrossbach\Linkwise\Support;

use Illuminate\Support\Facades\Cache;
use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

class SafeEntrySaver
{
    /**
     * Load an entry and capture its current state hash.
     *
     * @return array{0: Entry, 1: string} [entry, hash]
     */
    public static function load(string $entryId): array
    {
        $entry = EntryFacade::find($entryId);

        if (! $entry) {
            return [null, ''];
        }

        return [$entry, self::hash($entry)];
    }

    /**
     * Save entry only if content hasn't changed since loading.
     *
     * @throws EntryConflictException if the entry was modified by another user
     */
    public static function save(Entry $entry, string $expectedHash): void
    {
        // Reload the entry from disk to get current state
        $current = EntryFacade::find($entry->id());

        if (! $current) {
            if ($expectedHash !== '') {
                throw new EntryConflictException(
                    entryId: $entry->id(),
                    entryTitle: 'Deleted entry',
                );
            }

            self::saveWithCascadeGuard($entry);

            return;
        }

        $currentHash = self::hash($current);

        if ($currentHash !== $expectedHash) {
            throw new EntryConflictException(
                entryId: $entry->id(),
                entryTitle: $entry->get('title') ?? $entry->slug() ?? $entry->id(),
            );
        }

        self::saveWithCascadeGuard($entry);
    }

    /**
     * Persist the entry while suppressing the auto-apply cascade.
     *
     * Every Linkwise-initiated save (manual Apply Rule, bulk insert, unlink,
     * etc.) goes through this helper. We mark the entry as "Linkwise just
     * saved this" via the same cache key the AutoLinkOnEntrySaveSubscriber
     * checks; the subscriber sees the flag and skips its cascade.
     *
     * Editor saves don't go through this method, so they trigger auto-apply
     * normally — which is exactly the intent: auto-apply runs on user saves,
     * not on chained Linkwise operations.
     */
    protected static function saveWithCascadeGuard(Entry $entry): void
    {
        $flagKey = 'linkwise:autoapply:processing:'.$entry->id();
        Cache::put($flagKey, true, 60);
        try {
            $entry->save();
        } finally {
            Cache::forget($flagKey);
        }
    }

    /**
     * Verify entry hashes from client. Returns array of conflicted entry titles keyed by ID.
     *
     * @param  array<string, string>  $entryHashes  [entryId => expectedHash]
     * @return array<string, string>  [entryId => entryTitle] of conflicted entries
     */
    public static function verifyHashes(array $entryHashes): array
    {
        $conflicts = [];

        foreach ($entryHashes as $entryId => $expectedHash) {
            $entry = EntryFacade::find($entryId);
            if ($entry && self::hash($entry) !== $expectedHash) {
                $conflicts[$entryId] = $entry->get('title') ?? $entryId;
            }
        }

        return $conflicts;
    }

    /**
     * Compute a hash of the entry's data for conflict detection.
     *
     * Volatile fields (updated_at, updated_by, last_modified) are stripped:
     * Statamic refreshes them on every save() — even saves triggered by
     * unrelated subscribers (index updates, supplements, etc.) — which would
     * otherwise produce a false-positive "modified by another editor" 409
     * during bulk runs.
     *
     * Real edits change content fields (title, body, etc.) which DO go into
     * the hash, so this stays safe against actual concurrent modification.
     */
    public static function hash(Entry $entry): string
    {
        $data = $entry->data()->all();
        unset($data['updated_at'], $data['updated_by'], $data['last_modified']);

        return md5(json_encode($data));
    }
}
