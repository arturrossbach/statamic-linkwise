<?php

namespace Arturrossbach\Linkwise\Relink;

use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\BulkSnapshotStore;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;
use Statamic\Facades\Entry;

/**
 * Atomic re-link: remove existing link + insert new link on the SAME
 * in-memory entry tree, one save, hash-checked.
 *
 * Bug 17 Phase C (2026-05-11): replaces the previous 2-step flow
 * (URL-Changer apply unlink + async link-insert) that produced four
 * symptoms from one architectural root:
 *
 *   - Bug 17 partial-state (Step 1 commits, Step 2 fails → no rollback)
 *   - Activity log shows "removed" not "re-linked"
 *   - Bulk re-link blocks at Step 2's JobLock for next item's Step 1
 *   - Two completion toasts per single user action
 *
 * The atomic command removes all four by doing both mutations on one
 * Entry instance and saving once. Same-target anchor expansion (the
 * advisor-flagged common case) trivially works because the old mark
 * is genuinely removed from the in-memory tree before the new mark
 * is inserted — no Phase-A "original_href simulation" needed.
 *
 * Reuses UrlReplacer's existing `replaceNthIn*` primitives for the
 * removal step (content-level, no JobLock, no HTTP coupling — verified
 * via the C1 tinker pass before writing this service).
 *
 * Reason taxonomy (matches Phase A's controller for frontend reuse):
 *
 *   - `entry_not_found` — entry deleted since modal opened
 *   - `entry_changed`   — content_hash mismatch OR removal didn't find
 *                         the (href, occurrence_index, anchor) triple
 *                         (= stale modal); SafeEntrySaver::save also
 *                         maps EntryConflictException here
 *   - `anchor_not_found` — new anchor not at word boundary in the
 *                         post-removal in-memory tree
 *   - `context_mismatch` — captured sentence_context absent
 *   - `crosses_existing_link` — new anchor spans a different-target
 *                         existing link (NOT the one we just removed);
 *                         blocking_href names it
 *   - `already_linked_to_target` — full-overlap idempotent case (rare
 *                         after removal step — would mean user expanded
 *                         into a link to the same new target)
 */
class RelinkService
{
    public function __construct(
        protected UrlReplacer $urlReplacer,
        protected BulkSnapshotStore $snapshotStore,
    ) {}

    /**
     * @param  string  $sourceEntryId
     * @param  string  $originalHref       The link href Step-A removes
     * @param  int  $occurrenceIndex       Which occurrence of $originalHref to remove
     * @param  string  $originalAnchor     Anchor-fingerprint guard (UrlReplacer rejects
     *                                     wrong-occurrence even when index alone matches)
     * @param  string  $newAnchor          The anchor text Step-C wraps
     * @param  string  $newHref            The link href Step-C inserts
     * @param  string|null  $sentenceContext  Surrounding sentence at scan time;
     *                                        used as context-fingerprint guard during insert
     * @param  string|null  $expectedHash   When set, refuses if entry has changed since load
     *
     * @return array{ok: bool, reason?: string, blocking_href?: string, message?: string, new_hash?: string}
     */
    public function relink(
        string $sourceEntryId,
        string $originalHref,
        int $occurrenceIndex,
        string $originalAnchor,
        string $newAnchor,
        string $newHref,
        ?string $sentenceContext = null,
        ?string $expectedHash = null,
        ?string $reverts = null,
    ): array {
        // Idempotency — user clicked re-link without changing anchor or
        // target. Cheap to handle here; prevents activity-log spam.
        if ($originalHref === $newHref && $originalAnchor === $newAnchor) {
            return ['ok' => true, 'reason' => 'noop'];
        }

        [$entry, $currentHash] = SafeEntrySaver::load($sourceEntryId);
        if (! $entry) {
            return [
                'ok' => false,
                'reason' => 'entry_not_found',
                'message' => 'Eintrag wurde inzwischen gelöscht.',
            ];
        }

        if ($expectedHash !== null && $expectedHash !== '' && $expectedHash !== $currentHash) {
            return [
                'ok' => false,
                'reason' => 'entry_changed',
                'message' => 'Eintrag wurde inzwischen geändert. Bitte Modal schließen und neu öffnen.',
            ];
        }

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return [
                'ok' => false,
                'reason' => 'entry_not_found',
                'message' => 'Eintrag-Schema konnte nicht geladen werden.',
            ];
        }

        // ─── Step A: remove the original link ──────────────────────────
        //
        // Walk fields in their natural order. First field whose walker
        // signals `actually_replaced` claims this re-link — both Step C
        // (insert) and Step D (save) operate on the same Entry instance,
        // so the field-level locality is preserved.
        //
        // didReplace=false in UrlReplacer is intentionally combined-catchall
        // (anchor mismatch + occurrence out of range collapse into one
        // "stale modal" user signal — see C1 tinker verification notes).
        $removalField = null;
        $removalFieldType = null;
        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);
            $type = $field->type();

            if ($type === 'bard' && is_array($value) && ! empty($value)) {
                [$modified, $did] = $this->urlReplacer->replaceNthInBard(
                    $value, $originalHref, $originalHref, UrlHelper::UNLINK, $occurrenceIndex, $originalAnchor
                );
                if ($did) {
                    $entry->set($handle, $modified);
                    $removalField = $handle;
                    $removalFieldType = 'bard';
                    break;
                }
            } elseif ($type === 'replicator' && is_array($value) && ! empty($value)) {
                [$modified, $did] = $this->urlReplacer->replaceNthInReplicator(
                    $value, $originalHref, $originalHref, UrlHelper::UNLINK, $occurrenceIndex, $originalAnchor
                );
                if ($did) {
                    $entry->set($handle, $modified);
                    $removalField = $handle;
                    $removalFieldType = 'replicator';
                    break;
                }
            } elseif ($type === 'markdown' && is_string($value) && ! empty($value) && $handle !== 'title') {
                [$modified, $did] = $this->urlReplacer->replaceNthInMarkdown(
                    $value, $originalHref, UrlHelper::UNLINK, $occurrenceIndex, $originalAnchor
                );
                if ($did) {
                    $entry->set($handle, $modified);
                    $removalField = $handle;
                    $removalFieldType = 'markdown';
                    break;
                }
            }
        }

        if ($removalField === null) {
            return [
                'ok' => false,
                'reason' => 'entry_changed',
                'message' => 'Der ursprüngliche Link wurde nicht gefunden — Eintrag wurde inzwischen geändert. Bitte Modal schließen und neu öffnen.',
            ];
        }

        // ─── Step B: validate the insert on the post-removal tree ─────
        //
        // The mark is genuinely gone from $entry's in-memory state now,
        // so canInsertLinkIntoBardContent operates on real post-Step-A
        // truth — no `originalHref` simulation needed (Phase A workaround
        // becomes unnecessary; that parameter will be removed in C6).
        //
        // If validation says no, return WITHOUT calling save — the
        // in-memory entry is dirty but never persisted, no partial state.
        $insertValue = $entry->get($removalField);
        $analysis = $this->analyzeInsert($removalFieldType, $insertValue, $newAnchor, $newHref, $sentenceContext);

        if (! ($analysis['ok'] ?? false)) {
            return array_merge($analysis, [
                'message' => $this->messageForReason(
                    $analysis['reason'] ?? 'anchor_not_found',
                    $analysis['blocking_href'] ?? null,
                ),
            ]);
        }

        // ─── Step C: insert the new link ──────────────────────────────
        $inserted = $this->insert($removalFieldType, $insertValue, $newAnchor, $newHref, $sentenceContext);
        if ($inserted === null) {
            // Validation said yes but insertion failed — should not
            // happen if findValidMatchPosition and the inserter agree.
            // Defensive: don't save.
            return [
                'ok' => false,
                'reason' => 'unexpected',
                'message' => 'Unerwarteter Fehler bei der Insert-Phase trotz Pre-Validierung.',
            ];
        }
        $entry->set($removalField, $inserted);

        // ─── Step D: save once, hash-checked ──────────────────────────
        //
        // Pass the re-link context to the validator: the bm we just
        // removed will partially overlap the new am (same href, different
        // anchor span). Without the context, ContentSafetyValidator's
        // partial-overlap check would refuse the save — it can't tell an
        // intentional re-anchor from a Bug B silent split. The context
        // declares the substitution explicitly so the overlap is no
        // longer silent; all other bms still get the full check.
        $relinkContext = [
            'field' => $removalField,
            'href' => $originalHref,
            'occurrence_index' => $occurrenceIndex,
        ];

        try {
            SafeEntrySaver::save($entry, $currentHash, $relinkContext);
        } catch (EntryConflictException) {
            return [
                'ok' => false,
                'reason' => 'entry_changed',
                'message' => 'Eintrag wurde inzwischen geändert. Bitte Modal schließen und neu öffnen.',
            ];
        }

        $newHash = SafeEntrySaver::hash($entry);

        // ─── Activity log: record this re-link as ONE snapshot ────────
        //
        // Earlier 2-step flow logged Step 1 as "url-changer unlink" and
        // Step 2 as "link insert" — two separate events that looked like
        // the user did two unrelated operations. Atomic re-link records
        // a single kind='relink' snapshot whose summary names both ends
        // of the transition (original anchor/href → new anchor/href).
        //
        // Recording is best-effort: a snapshot-write failure must never
        // break the save the user already saw succeed. Same convention
        // BulkSnapshotStore uses internally for its file-write retries.
        $this->recordRelinkSnapshot(
            sourceEntryId: $sourceEntryId,
            originalHref: $originalHref,
            occurrenceIndex: $occurrenceIndex,
            originalAnchor: $originalAnchor,
            newAnchor: $newAnchor,
            newHref: $newHref,
            sentenceContext: $sentenceContext,
            field: $removalField,
            preHash: $currentHash,
            postHash: $newHash,
            reverts: $reverts,
        );

        return [
            'ok' => true,
            'new_hash' => $newHash,
            'field' => $removalField,
        ];
    }

    /**
     * Write the activity-log entry for a completed re-link.
     *
     * Items shape carries everything revertHelper.js needs to build the
     * inverse-relink payload: swap (original_*, new_*) and POST back to
     * /cp/linkwise/relink with the original snapshot's id in `reverts`.
     */
    protected function recordRelinkSnapshot(
        string $sourceEntryId,
        string $originalHref,
        int $occurrenceIndex,
        string $originalAnchor,
        string $newAnchor,
        string $newHref,
        ?string $sentenceContext,
        string $field,
        string $preHash,
        string $postHash,
        ?string $reverts,
    ): void {
        try {
            $summary = [
                'source_entry_id' => $sourceEntryId,
                'field' => $field,
                'original_href' => $originalHref,
                'original_anchor' => $originalAnchor,
                'new_href' => $newHref,
                'new_anchor' => $newAnchor,
                'occurrence_index' => $occurrenceIndex,
                'sentence_context' => $sentenceContext,
            ];
            if ($reverts !== null && $reverts !== '') {
                $summary['reverts'] = $reverts;
            }

            $item = [
                'entry_id' => $sourceEntryId,
                'field' => $field,
                'original_href' => $originalHref,
                'original_anchor' => $originalAnchor,
                'new_href' => $newHref,
                'new_anchor' => $newAnchor,
                'occurrence_index' => $occurrenceIndex,
                'sentence_context' => $sentenceContext,
            ];

            $snapshotId = $this->snapshotStore->record(
                kind: 'relink',
                entryIds: [$sourceEntryId],
                preHashes: [$sourceEntryId => $preHash],
                summary: $summary,
                items: [$item],
            );

            $this->snapshotStore->recordPostHashes($snapshotId, [$sourceEntryId => $postHash]);
            $this->snapshotStore->markCompleted($snapshotId, [
                'succeeded' => 1,
                'skipped' => 0,
                'errors' => (object) [],
            ]);

            // Mark the upstream snapshot as reverted when this re-link
            // is itself a revert dispatch — closes the activity-log
            // lineage so the original entry shows "reverted at …".
            if ($reverts !== null && $reverts !== '') {
                $this->snapshotStore->markReverted($reverts);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[Linkwise] RelinkService snapshot record failed — '.$e->getMessage()
            );
        }
    }

    /**
     * Field-type-dispatched dry-run analyzer.
     *
     * Bard + Replicator have granular reasons from canInsertLinkInto*.
     * Markdown is coarse — the existing insertLinkIntoMarkdown returns
     * null/non-null only. We map null → anchor_not_found (consistent
     * with Phase A's coarse markdown treatment).
     */
    protected function analyzeInsert(string $type, mixed $value, string $newAnchor, string $newHref, ?string $sentenceContext): array
    {
        if ($type === 'bard') {
            return BardLinkInserter::canInsertLinkIntoBardContent(
                $value, $newAnchor, $newHref, false, $sentenceContext
            );
        }
        if ($type === 'replicator') {
            return BardLinkInserter::canInsertLinkIntoReplicator(
                $value, $newAnchor, $newHref, false, $sentenceContext
            );
        }
        if ($type === 'markdown') {
            $probe = BardLinkInserter::insertLinkIntoMarkdown(
                $value, $newAnchor, $newHref, false, $sentenceContext
            );

            return $probe !== null
                ? ['ok' => true]
                : ['ok' => false, 'reason' => 'anchor_not_found'];
        }

        return ['ok' => false, 'reason' => 'anchor_not_found'];
    }

    /**
     * Field-type-dispatched mutator. Returns modified value or null.
     */
    protected function insert(string $type, mixed $value, string $newAnchor, string $newHref, ?string $sentenceContext): mixed
    {
        if ($type === 'bard') {
            return BardLinkInserter::insertLinkWithHref($value, $newAnchor, $newHref, false, $sentenceContext);
        }
        if ($type === 'replicator') {
            return BardLinkInserter::processReplicatorWithHref($value, $newAnchor, $newHref, false, $sentenceContext);
        }
        if ($type === 'markdown') {
            return BardLinkInserter::insertLinkIntoMarkdown($value, $newAnchor, $newHref, false, $sentenceContext);
        }

        return null;
    }

    /**
     * Centralised reason → German action-oriented message.
     * The DetailModal renders `message` verbatim in the per-item error
     * shown by the bulk-completion toast.
     */
    protected function messageForReason(string $reason, ?string $blockingHref): string
    {
        $blockingLabel = $blockingHref !== null ? $this->formatHref($blockingHref) : null;

        return match ($reason) {
            'anchor_not_found' => 'Der Anker-Text ist nicht (mehr) im Eintrag enthalten.',
            'context_mismatch' => 'Der ursprüngliche Satz-Kontext ist nicht mehr im Eintrag — bitte Modal neu öffnen.',
            'crosses_existing_link' => $blockingLabel !== null
                ? "Der Anker überlappt mit einem bestehenden Link auf {$blockingLabel} im selben Eintrag. Bitte den Link zuerst entfernen und erneut versuchen."
                : 'Der Anker überlappt mit einem bestehenden Link im selben Eintrag. Bitte den Link zuerst entfernen.',
            'already_linked_to_target' => 'Dieser Text ist bereits mit dem Ziel verlinkt.',
            default => 'Re-Link konnte nicht ausgeführt werden.',
        };
    }

    /**
     * Render `statamic://entry::uuid` as the entry's title where possible,
     * falling back to the raw URL. Same logic as Phase A's controller —
     * intentional duplication for now; if Phase C surfaces more href
     * formatting needs, extract into a shared HrefFormatter helper.
     */
    protected function formatHref(string $href): string
    {
        if (str_starts_with($href, 'statamic://entry::')) {
            $id = substr($href, strlen('statamic://entry::'));
            $entry = Entry::find($id);
            if ($entry) {
                return "'".$entry->get('title', $id)."'";
            }
        }

        return $href;
    }
}
