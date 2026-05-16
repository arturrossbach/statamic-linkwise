<?php

namespace Arturrossbach\Linkwise\Suggestions;

use Illuminate\Support\Facades\Cache;

/**
 * Cache for `InboundEngine::suggestFiltered($targetEntryId)` results.
 *
 * Sprint 6 REV-IB-01 — pairs with REV-IB-02 (strict-anchor filter). Without
 * a cache, every modal open re-runs the suggest + N dry-run-inserts pipeline
 * — minutes of spinner on Marketplace-Audience sites (>500 posts per
 * Domain-Entscheidung REV-BL-01).
 *
 * # Hit / invalidation strategy
 *
 * Single per-target cache key with a short TTL (5 min default). The
 * "suggestions" surface is non-authoritative (hints, not data), so up to
 * 5 minutes of staleness across an entry-edit is acceptable.
 *
 *   - HIT  → suggestion array returned, modal renders instantly
 *   - MISS → caller falls through to live compute, then store
 *   - EXPLICIT invalidate → caller invokes `forget($targetEntryId)`
 *     after a write the user just made to that entry
 *
 * # Why no global clear()
 *
 * Laravel cache wildcard / tag invalidation only works with redis or
 * memcached; the file driver (Statamic's default) doesn't support either.
 * Maintaining a manifest of cached target-ids just to enable global clear
 * would add complexity for very little win — TTL handles it. Per-target
 * `forget()` covers the explicit-edit path.
 *
 * # Storage
 *
 * Laravel Cache facade with the package's configured driver. Key shape:
 * `linkwise:inbound:suggestFiltered:{targetEntryId}` — flat, predictable,
 * easy to debug via `php artisan cache:clear` if ever needed.
 */
class InboundSuggestionCache
{
    public const DEFAULT_TTL_SECONDS = 300; // 5 minutes

    protected const KEY_PREFIX = 'linkwise:inbound:suggestFiltered:';

    /**
     * Read the cached suggestion list for a target entry.
     *
     * Returns `null` on miss (no row, expired row, or corrupt row). Caller
     * should fall through to a live compute + `store()` call.
     *
     * Returns `[]` (empty array) on a HIT for "target has no inbound
     * suggestions" — distinguishable from null so we don't silently
     * re-compute every modal open for empty-result targets.
     *
     * @return InboundSuggestion[]|null
     */
    public function getCached(string $targetEntryId): ?array
    {
        $raw = Cache::get($this->keyFor($targetEntryId));
        if (! is_array($raw)) {
            return null;
        }

        $records = [];
        foreach ($raw as $data) {
            if (! is_array($data)) {
                continue;
            }
            try {
                $records[] = InboundSuggestion::fromArray($data);
            } catch (\InvalidArgumentException $e) {
                // Skip corrupt rows — modal still renders the valid ones,
                // missing rows resurface on the next TTL miss.
            }
        }

        return $records;
    }

    /**
     * Persist a freshly-computed suggestion list. Overwrites any prior
     * row for the same target.
     *
     * @param  InboundSuggestion[]  $suggestions
     */
    public function store(string $targetEntryId, array $suggestions, int $ttlSec = self::DEFAULT_TTL_SECONDS): void
    {
        $payload = array_map(fn (InboundSuggestion $s) => $s->toArray(), $suggestions);
        Cache::put($this->keyFor($targetEntryId), $payload, $ttlSec);
    }

    /**
     * Drop the cached row for one target. Called after an editor saves
     * the target entry (or the entry was the source of a recent bulk
     * write) so the next modal open computes fresh.
     */
    public function forget(string $targetEntryId): void
    {
        Cache::forget($this->keyFor($targetEntryId));
    }

    /**
     * Bulk-forget — for use by bulk-write commands inside their
     * `finalizeIndex()` step (Sprint 6 REV-IB-01 follow-up, user-reported
     * 2026-05-16: "Nach Bulk-Insert muss ich erst rescannen, sonst zeigt
     * das Inbound-Modal die schon-eingefügten Suggestions weiter und
     * der Count in der Haupttabelle steht still").
     *
     * Every bulk-write that mutates entry content invalidates the
     * suggestion-shape for the AFFECTED entries (both source and target
     * sides of any inserted/removed link). Without this, the next
     * `suggestFiltered()` call hits a cache row computed against the
     * pre-write entry state and silently returns stale suggestions.
     *
     * @param  list<string>  $entryIds  every entry the bulk touched —
     *                                  both source AND target sides for
     *                                  link-insertions, both source AND
     *                                  any internal-link targets for
     *                                  link-removals.
     */
    public function forgetMany(array $entryIds): void
    {
        foreach ($entryIds as $entryId) {
            if (is_string($entryId) && $entryId !== '') {
                Cache::forget($this->keyFor($entryId));
            }
        }
    }

    /** Cache key shape — kept stable for debug + `php artisan cache:forget` use. */
    protected function keyFor(string $targetEntryId): string
    {
        return self::KEY_PREFIX.$targetEntryId;
    }
}
