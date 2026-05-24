<?php

namespace Arturrossbach\Linkwise\Subscribers;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\JobLock;
use Statamic\Events\EntrySaved;

/**
 * Auto-applies eligible Auto-Link rules whenever an entry is saved.
 *
 * "Eligible" means: rule.active AND rule.autoApplyOnSave AND the global
 * setting `linkwise.auto_apply_on_save_enabled` is true. The rule must also
 * not target the saved entry itself (no self-links).
 *
 * BardLinkInserter handles the actual insert and is naturally idempotent:
 * it returns false if the anchor isn't found OR if it's already inside a
 * link mark. So re-saving an entry doesn't create duplicate links.
 *
 * Loop guard: each insert triggers $entry->save() which fires EntrySaved
 * again. Without the per-entry cache flag we'd recurse forever.
 */
class AutoLinkOnEntrySaveSubscriber
{
    public function __construct(
        protected AutoLinkManager $manager,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaved::class, self::class.'@handleSaved');
    }

    public function handleSaved(EntrySaved $event): void
    {
        $globalEnabled = (bool) config('linkwise.auto_apply_on_save_enabled', false);
        $entry = $event->entry;
        $entryId = $entry->id();

        // Loop guard — set during our own inserts so the EntrySaved fired by
        // BardLinkInserter's $entry->save() doesn't recurse back into us.
        $loopFlag = "linkwise:autoapply:processing:{$entryId}";
        if (Cache::get($loopFlag)) {
            return;
        }

        // Concurrency guard: skip auto-apply when ANY heavy bulk is in flight.
        // Without this, an editor saving an unrelated entry mid-bulk would fire
        // the subscriber, which would then write that entry while a Scan / URL
        // Changer / Bulk-Unlink / Apply-Rule batch is also writing entries on
        // its own schedule. SafeEntrySaver still prevents per-entry corruption
        // (hash mismatch → silent skip below in the BardLinkInserter try/catch),
        // but the user's manual save would silently NOT get auto-linked, and
        // they'd never know — there's no UI surface for "save was OK but auto-
        // apply was skipped". Better: skip explicitly + log so the operator can
        // see it; the user can re-trigger Apply Selected after the bulk done.
        if (JobLock::activeJob() !== null) {
            Log::info(
                "[Linkwise] AutoLinkOnEntrySaveSubscriber skipped auto-apply on entry {$entryId} — bulk in progress. User can re-run Apply Selected after the bulk completes.",
            );
            return;
        }

        try {
            // Hard-skip when the entry is not in any of the globally-allowed
            // collections (e.g. user excluded `globals`, `users` etc.).
            $allowedCollections = config('linkwise.collections', []);
            if (! empty($allowedCollections)
                && ! in_array($entry->collectionHandle(), $allowedCollections, true)
            ) {
                return;
            }

            // V1.2 Cross-Tab-B — resolve entry locale via same chain the
            // Indexer uses, to feed the rule's locale-scope filter.
            $entryLocale = null;
            try {
                $site = $entry->site();
                if ($site !== null) {
                    $entryLocale = \Arturrossbach\Linkwise\NLP\LanguageRegistry::resolveFor(
                        (string) ($site->lang() ?? '')
                    );
                }
            } catch (\Throwable) {
                // Single-site / malformed-fixture path — null leaves the
                // rule's matchesLocale() pass-through.
            }

            $eligibleRules = self::eligibleRulesForSave(
                $this->manager->loadRules(),
                $entryId,
                (string) $entry->collectionHandle(),
                $globalEnabled,
                $entryLocale,
            );

            if (empty($eligibleRules)) {
                return;
            }

            Cache::put($loopFlag, true, 60);

            $insertedAny = false;
            foreach ($eligibleRules as $rule) {
                try {
                    $inserted = BardLinkInserter::insertLinkIntoEntryWithHref(
                        $entryId,
                        $rule->keyword,
                        $rule->url,
                        $rule->caseSensitive,
                    );
                    if ($inserted) {
                        $insertedAny = true;
                    }
                } catch (\Throwable $e) {
                    Log::warning(
                        "[Linkwise] Auto-apply rule '{$rule->keyword}' failed on entry {$entryId}: ".$e->getMessage(),
                    );
                }
            }

            // Inbound-suggestion-cache invalidation (Sprint 6 REV-IB-01
            // follow-up). The entry now carries new outbound links so any
            // OTHER target that had this entry as a candidate source has
            // a stale suggestion-row. We only forget THIS entry's row —
            // cross-entry invalidation is bounded by the 5-min TTL.
            // Skipped when nothing actually inserted to avoid cache churn.
            if ($insertedAny) {
                try {
                    app(\Arturrossbach\Linkwise\Suggestions\InboundSuggestionCache::class)
                        ->forget($entryId);
                } catch (\Throwable $e) {
                    Log::warning('[Linkwise] AutoLinkOnEntrySaveSubscriber cache-forget failed: '.$e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Linkwise] AutoLinkOnEntrySaveSubscriber failed: '.$e->getMessage());
        } finally {
            Cache::forget($loopFlag);
        }
    }

    /**
     * Pure helper — selects which rules should fire on this save.
     * Extracted so we can unit-test the eligibility logic without mocking
     * Statamic events. The handleSaved() method is then a thin wrapper.
     *
     * Rules:
     *   - active=false → skip
     *   - rule targets the entry being saved → skip (no self-links)
     *   - rule has collection restriction and entry's collection isn't in it → skip
     *   - rule has locale restriction (V1.2 B/F1) and entry's locale isn't in it → skip
     *   - tri-state autoApplyOnSave:
     *       'never'         → skip
     *       'always'        → fire (regardless of global)
     *       'follow_global' → fire iff $globalEnabled
     *
     * @param  AutoLinkRule[]  $rules
     * @return AutoLinkRule[]
     */
    public static function eligibleRulesForSave(
        array $rules,
        string $entryId,
        string $entryCollection,
        bool $globalEnabled,
        ?string $entryLocale = null,
    ): array {
        return array_values(array_filter($rules, function ($r) use ($entryId, $entryCollection, $globalEnabled, $entryLocale) {
            if (! $r->active) {
                return false;
            }
            if ($r->targetEntryId === $entryId) {
                return false;
            }
            if (! empty($r->collections) && ! in_array($entryCollection, $r->collections, true)) {
                return false;
            }
            // V1.2 Cross-Tab-B — per-rule locale-scope. Same logic as the
            // AutoLinkApplier preview/write filter, applied here to close
            // the silent on-save path. A DE-only rule must not fire on
            // EN entries even when the rule's keyword happens to appear
            // in their content (loanwords etc.).
            if (! $r->matchesLocale($entryLocale)) {
                return false;
            }

            return match ($r->autoApplyOnSave) {
                'always' => true,
                'never' => false,
                default => $globalEnabled,
            };
        }));
    }
}
