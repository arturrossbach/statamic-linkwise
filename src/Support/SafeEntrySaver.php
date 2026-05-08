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
        // Reload the entry from disk to get current state. We need this for
        // both the conflict-check below AND the diff-based content-safety
        // validation: we only block saves that INTRODUCE new corruption,
        // not saves that touch entries with pre-existing corruption from
        // earlier dev iterations or manual paste.
        $current = EntryFacade::find($entry->id());

        if (! $current) {
            if ($expectedHash !== '') {
                throw new EntryConflictException(
                    entryId: $entry->id(),
                    entryTitle: 'Deleted entry',
                );
            }

            // First-ever save (no on-disk state to diff against): fall back
            // to absolute validation. Anything corrupt is genuinely new.
            ContentSafetyValidator::ensureSafe($entry);
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

        // Diff-based content-safety check: throws ContentCorruptionException
        // only when this save would introduce NEW violations the on-disk
        // state didn't already have. Linkwise is responsible for what we
        // change — pre-existing corruption is the user's data hygiene
        // problem, surfaced by `php artisan linkwise:audit`. Without this
        // diff, legitimate operations (e.g. removing a clean link from an
        // entry that ALSO contains an unrelated pre-existing corrupt link)
        // would be blocked too, which is the wrong default.
        ContentSafetyValidator::ensureNoNewViolations($current, $entry);

        // Link-coverage runtime gate (added 2026-05-08 after Bug B):
        // refuse saves that partially destroy an existing link mark.
        // Last line of defense if a future code path slips past the
        // walker's partial-overlap skip — fail-closed protects user data
        // even when tests have a coverage gap.
        ContentSafetyValidator::ensureLinkCoveragePreserved($current, $entry);

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
     * Reads from the on-disk file (not $entry->data()) so the hash reflects
     * the persisted source of truth. Statamic's in-memory Entry instance can
     * temporarily hold pre-canonicalisation data (e.g. Bard fieldtype
     * normalisations applied during save) that re-serialises to the same YAML
     * but produces a different json_encode byte stream. Hashing data() then
     * caused recordPostHashesForEntries to capture a hash that no later
     * Entry::find could ever reproduce, falsely flagging revert-preview
     * entries as "modified" with no user edit.
     *
     * Volatile fields (updated_at, updated_by, last_modified) are stripped:
     * Statamic rewrites them on every save() — even cascading subscribers —
     * so leaving them in would produce false 409 conflicts during bulk runs.
     *
     * Fallback to data()-based hash for new entries that have no on-disk
     * path yet (creation flow). Those have $expectedHash = '' so the value
     * is never compared.
     */
    public static function hash(Entry $entry): string
    {
        $path = method_exists($entry, 'path') ? $entry->path() : null;
        if (is_string($path) && $path !== '' && is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $stripped = preg_replace(
                    '/^(updated_at|updated_by|last_modified):.*$\n?/m',
                    '',
                    $raw,
                );

                return md5($stripped);
            }
        }

        $data = $entry->data()->all();
        unset($data['updated_at'], $data['updated_by'], $data['last_modified']);

        return md5(json_encode($data));
    }
}
