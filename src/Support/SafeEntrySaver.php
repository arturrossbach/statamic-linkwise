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
     * Contract: returns `[null, '']` when the entry does not exist
     * (deleted between load and re-load, never existed, wrong locale).
     * Callers MUST null-check $entry before using $hash — see
     * RelinkService:97, BardLinkInserter:244/328/420, UrlReplacer:83 for
     * the canonical pattern (`if (! $entry) { ... }`).
     *
     * @return array{0: ?Entry, 1: string} [entry-or-null, hash-or-empty-string]
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
     * Two non-obvious behaviors beyond the conflict check:
     *
     * 1. **Bard-fields are normalized in-place before save.** Adjacent
     *    text nodes with identical mark-sets are merged into single
     *    nodes via {@see BardWalker::normalizeChildren()}. This is the
     *    invariant introduced 2026-05-11 to close Bug 16 (fragmented
     *    marks → silent NO-OP in URL-Changer apply). Side-effect: a
     *    save can change on-disk bytes in Bard fields the caller didn't
     *    explicitly mutate, when those fields had pre-existing fragments
     *    — a free cleanup. The change is semantically transparent
     *    (rendering identical) but visible in git-diffs of the .md files.
     *
     * 2. **`$current` (loaded from disk) is also normalized in-memory
     *    before validator comparison.** This is NOT a disk write — just
     *    a temporary mutation of the in-memory Statamic Entry instance
     *    that `EntryFacade::find()` hands us. Verified isolated from
     *    Stache: subsequent finds return fresh instances, our mutation
     *    does not leak. Required because validator checks compare
     *    before/after — without normalizing both sides,
     *    `ensureLinkCoveragePreserved` false-positives on legitimate
     *    fragment-cleanup (Mark1+Mark2 → merged After-Mark looks like
     *    Bug B partial-overlap at the offset comparison level).
     *
     * @throws EntryConflictException if the entry was modified by another user
     */
    public static function save(Entry $entry, string $expectedHash, ?array $relinkContext = null): void
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
            // Normalize first so the absolute check doesn't false-fire on
            // adjacent same-mark fragments injected upstream (paste-from-
            // editor, third-party tooling) — those are legitimate to save.
            self::normalizeBardFieldsInPlace($entry);
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

        // Normalize both sides BEFORE the validator chain. Without this,
        // `ensureLinkCoveragePreserved` reports Bug-B partial-overlap when
        // $current has pre-existing adjacent same-href fragments and the
        // merged $entry has them collapsed: the validator sees Mark2's
        // offset shift onto Mark1's and interprets the overlap as
        // corruption (advisor-flagged trap, 2026-05-11). With both sides
        // normalized, the validator compares apples-to-apples in their
        // canonical form. The mutation on $current is safe — Statamic's
        // Entry instances from EntryFacade::find are isolated per call
        // (verified 2026-05-11), not shared singletons.
        self::normalizeBardFieldsInPlace($current);
        self::normalizeBardFieldsInPlace($entry);

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
        ContentSafetyValidator::ensureLinkCoveragePreserved($current, $entry, $relinkContext);

        // Fragmentation gate (added 2026-05-11 alongside the normalize
        // step). After the in-place normalize above this should be
        // trivially 0=0 for both sides — but if a future code path skips
        // the normalize (test fixtures, third-party direct entry
        // mutation), this catches the regression fail-closed.
        ContentSafetyValidator::ensureNoNewAdjacentSameMarks($current, $entry);

        self::saveWithCascadeGuard($entry);
    }

    /**
     * Walk every Bard / Replicator-nested-Bard field on the entry and
     * apply {@see BardWalker::normalizeChildren()} to it. In-place
     * mutation of the supplied Entry instance.
     *
     * Markdown fields are atomic `[anchor](url)` syntax → no fragments
     * possible → skipped (consistent with the validator's scope).
     *
     * Idempotent: a tree that's already normalized roundtrips unchanged.
     */
    protected static function normalizeBardFieldsInPlace(Entry $entry): void
    {
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            // No blueprint = nothing to normalize. Same retreat the
            // validators use; we don't fight the data model here.
            return;
        }

        foreach ($fields as $handle => $field) {
            $type = $field->type();
            $value = $entry->get($handle);

            if ($type === 'bard' && is_array($value) && ! empty($value)) {
                $entry->set($handle, BardWalker::normalizeChildren($value));
            } elseif ($type === 'replicator' && is_array($value) && ! empty($value)) {
                $entry->set($handle, self::normalizeReplicatorBardFragments($value));
            }
        }
    }

    /**
     * Recurse into Replicator sets, normalize every nested Bard
     * fragment. String values and metadata keys (type/id/enabled)
     * pass through unchanged — they aren't Bard trees.
     *
     * Same traversal contract as
     * {@see ContentSafetyValidator::countAdjacentSameMarkPairsInReplicator()}
     * and the rest of the Linkwise replicator walkers — keeps the
     * invariant uniform across reader and writer paths.
     */
    protected static function normalizeReplicatorBardFragments(array $sets): array
    {
        foreach ($sets as $i => $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $sets[$i][$key] = BardWalker::normalizeChildren($value);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $sets[$i][$key] = self::normalizeReplicatorBardFragments($value);
                }
            }
        }
        return $sets;
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
