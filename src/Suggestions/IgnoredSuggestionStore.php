<?php

namespace Arturrossbach\Linkwise\Suggestions;

/**
 * Per-user (well, per-site) block-list of suggestion pairs the editor
 * has explicitly marked as "don't suggest this link again".
 *
 * ## Why a store
 *
 * The Suggestion Engine ranks entries by TF-IDF keyword overlap +
 * title-phrase match + custom keywords. Sometimes the algorithm finds
 * a real lexical overlap that's semantically irrelevant — e.g. a
 * travel-blog entry mentioning "Erdnuss-Soba-Nudeln" as a beilage
 * gets matched against a DB-tuning entry that happens to share the
 * same rare phrase. The editor doesn't want to permanently fix the
 * source content; they just want to hide that specific pair.
 *
 * ## Undirected pairs
 *
 * Ignoring "Source X → Target Y" automatically also hides "Source Y
 * → Target X" — they're the same conceptual pair. Storage normalises
 * by sorting the two entry-ids so we don't double-record.
 *
 * ## Survives re-scan
 *
 * The file is intentionally NOT cleared on Scan Content. If the
 * engine re-generates the same pair after a re-index, the ignore
 * still applies. Editor decided this once, that decision sticks
 * until they explicitly un-ignore.
 *
 * ## Storage shape
 *
 * `storage/linkwise/ignored-suggestions.json`:
 *
 *     [
 *       ["entryA-uuid", "entryB-uuid"],
 *       ["entryC-uuid", "entryD-uuid"]
 *     ]
 *
 * Each inner array has exactly 2 elements, sorted ASCII-ascending so
 * the same pair always serialises identically regardless of which
 * direction the editor ignored from.
 *
 * Parity with {@see \Arturrossbach\Linkwise\Keywords\ExcludedContentKeywordManager}:
 * same atomic flock-based write pattern, same `JsonFileStore::load`
 * read path, same "best-effort, never throw upward" stance.
 */
class IgnoredSuggestionStore
{
    protected string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('linkwise');
    }

    /**
     * Mark a suggestion pair (sourceEntryId, targetEntryId) as ignored.
     * Order doesn't matter — the pair is stored sorted internally.
     * Idempotent — calling twice is a no-op.
     */
    public function ignore(string $entryIdA, string $entryIdB): void
    {
        if ($entryIdA === '' || $entryIdB === '' || $entryIdA === $entryIdB) {
            return;
        }

        $pair = $this->normalisePair($entryIdA, $entryIdB);

        $this->mutate(function (array $pairs) use ($pair) {
            $key = $pair[0].'::'.$pair[1];
            $existing = array_map(fn ($p) => $p[0].'::'.$p[1], $pairs);
            if (in_array($key, $existing, true)) {
                return $pairs; // already ignored
            }
            $pairs[] = $pair;

            return $pairs;
        });
    }

    /**
     * Remove an ignore-mark for a pair. Order doesn't matter.
     * Idempotent — removing a non-existent pair is a no-op.
     */
    public function unignore(string $entryIdA, string $entryIdB): void
    {
        if ($entryIdA === '' || $entryIdB === '' || $entryIdA === $entryIdB) {
            return;
        }

        $pair = $this->normalisePair($entryIdA, $entryIdB);

        $this->mutate(function (array $pairs) use ($pair) {
            $key = $pair[0].'::'.$pair[1];

            return array_values(array_filter(
                $pairs,
                fn ($p) => ($p[0].'::'.$p[1]) !== $key,
            ));
        });
    }

    /**
     * True if the pair is currently ignored. Undirected.
     */
    public function isIgnored(string $entryIdA, string $entryIdB): bool
    {
        if ($entryIdA === '' || $entryIdB === '' || $entryIdA === $entryIdB) {
            return false;
        }

        $pair = $this->normalisePair($entryIdA, $entryIdB);
        $key = $pair[0].'::'.$pair[1];

        foreach ($this->loadAll() as $existing) {
            if (($existing[0] ?? '').'::'.($existing[1] ?? '') === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * All ignored pairs involving the given entry, returned as an
     * array of "the other side" entry-ids. Used by the count-subtraction
     * logic in InertiaPagesController::links to drop `cached_total` by
     * the number of ignored pairs this entry participates in.
     *
     * @return list<string>
     */
    public function ignoredPartnersOf(string $entryId): array
    {
        if ($entryId === '') {
            return [];
        }

        $partners = [];
        foreach ($this->loadAll() as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            if ($pair[0] === $entryId) {
                $partners[] = $pair[1];
            } elseif ($pair[1] === $entryId) {
                $partners[] = $pair[0];
            }
        }

        return $partners;
    }

    /**
     * Number of ignored pairs this entry participates in. Convenience
     * for badge-count subtraction; same as `count(ignoredPartnersOf())`
     * but avoids the array allocation for hot-path callers.
     */
    public function ignoredCountFor(string $entryId): int
    {
        if ($entryId === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->loadAll() as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            if ($pair[0] === $entryId || $pair[1] === $entryId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function loadAll(): array
    {
        $data = \Arturrossbach\Linkwise\Support\JsonFileStore::load(
            $this->getPath(),
            [],
            'IgnoredSuggestionStore::loadAll',
        );

        if (! is_array($data)) {
            return [];
        }

        // Filter to well-formed pairs only — guards against partial-write
        // corruption or hand-edits with extra fields.
        return array_values(array_filter(
            $data,
            fn ($p) => is_array($p) && count($p) === 2 && is_string($p[0]) && is_string($p[1]),
        ));
    }

    /**
     * Wipe the entire ignored-list. Settings-page "Clear all ignored"
     * future feature reaches here.
     */
    public function clearAll(): void
    {
        $this->mutate(fn () => []);
    }

    /**
     * @param  callable(array): array  $mutator
     */
    protected function mutate(callable $mutator): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $path = $this->getPath();
        $fp = fopen($path, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                return;
            }

            rewind($fp);
            $contents = stream_get_contents($fp);
            $data = $contents ? json_decode($contents, true) : [];
            if (! is_array($data)) {
                $data = [];
            }

            $next = $mutator($data);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Normalise an unordered pair to a stable, sorted shape so the
     * same conceptual pair always serialises identically regardless
     * of the order in which entryIds were passed to ignore().
     *
     * @return array{0: string, 1: string}
     */
    protected function normalisePair(string $a, string $b): array
    {
        return strcmp($a, $b) <= 0 ? [$a, $b] : [$b, $a];
    }

    protected function getPath(): string
    {
        return $this->storagePath.'/ignored-suggestions.json';
    }
}
