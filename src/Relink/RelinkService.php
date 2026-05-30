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
 * flagged common case) trivially works because the old mark
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
     * @param  string|null  $sentenceContext  Surrounding sentence captured at scan-time.
     *   Carried into the activity-log snapshot's Context column so the revert
     *   drawer can show the editor's view at apply-time, even after the entry
     *   has changed. NOT a guard during insert — that role moved to Step A's
     *   exact-position output (Bug 17-20 position-passing refactor,
     *   2026-05-12). REV-RL-03 cleared the older "context-fingerprint guard"
     *   docblock that lied about this param's purpose.
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
                'message' => 'The entry has been deleted in the meantime.',
            ];
        }

        if ($expectedHash !== null && $expectedHash !== '' && $expectedHash !== $currentHash) {
            return [
                'ok' => false,
                'reason' => 'entry_changed',
                'message' => 'The entry has been modified in the meantime. Please close the modal and reopen it.',
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
        //
        // REV-RL-02 (2026-05-13): field-type cascade extracted to
        // EntryFieldWalker::firstMutation. Helper iterates blueprint fields,
        // calls our per-type callback, writes back on first mutation +
        // returns {handle, field_type, result}. Callback returns
        // ['value' => $newValue, 'position' => $position] on success or
        // null to skip — the empty-value + title-handle guards are now
        // handled by the helper, not by us.
        $mutation = \Arturrossbach\Linkwise\Support\EntryFieldWalker::firstMutation(
            $entry,
            onBard: function (array $value) use ($originalHref, $occurrenceIndex, $originalAnchor): ?array {
                [$modified, $did, $position] = $this->urlReplacer->replaceNthInBard(
                    $value, $originalHref, $originalHref, UrlHelper::UNLINK, $occurrenceIndex, $originalAnchor
                );
                return $did ? ['value' => $modified, 'position' => $position] : null;
            },
            onReplicator: function (array $value) use ($originalHref, $occurrenceIndex, $originalAnchor): ?array {
                [$modified, $did, $position] = $this->urlReplacer->replaceNthInReplicator(
                    $value, $originalHref, $originalHref, UrlHelper::UNLINK, $occurrenceIndex, $originalAnchor
                );
                return $did ? ['value' => $modified, 'position' => $position] : null;
            },
            onMarkdown: function (string $value) use ($originalHref, $occurrenceIndex, $originalAnchor): ?array {
                // Re-Link operates on one specific URL — passing $originalHref as
                // both $search and $oldUrl matches the existing Bard/Replicator
                // calls on the previous lines (exact-mode-style targeting).
                [$modified, $did, $position] = $this->urlReplacer->replaceNthInMarkdown(
                    $value, $originalHref, $originalHref, UrlHelper::UNLINK, $occurrenceIndex, $originalAnchor
                );
                return $did ? ['value' => $modified, 'position' => $position] : null;
            },
        );

        if ($mutation === null) {
            return [
                'ok' => false,
                'reason' => 'entry_changed',
                'message' => 'The original link could not be found — the entry has been modified in the meantime. Please close the modal and reopen it.',
            ];
        }

        $removalField = $mutation['handle'];
        $removalFieldType = $mutation['field_type'];
        $removalPosition = $mutation['result']['position'];

        // ─── Step B+C combined: insert at position (Bug 17–20 root refactor) ─
        //
        // Step A returned the EXACT location where the mark was removed.
        // Compute the new anchor's position from that + the user's intended
        // anchor edit:
        //   - new contains old (expansion):    new starts BEFORE original
        //   - old contains new (shrink):        new starts INSIDE original
        //   - neither contains the other:       refuse — the user's edit isn't
        //                                       a pure anchor change
        // No more find-first walk, no sentence-context fingerprint, no
        // anchor_offset_in_context plumbing.
        $newPosition = $this->deriveNewPosition($removalPosition, $originalAnchor, $newAnchor);
        if ($newPosition === null) {
            return [
                'ok' => false,
                'reason' => 'anchor_edit_not_supported',
                'message' => 'The anchor edit is not a simple expansion or shortening — please delete the link and add it again.',
            ];
        }

        $insertValue = $entry->get($removalField);
        $insertResult = $this->insertAtPosition($removalFieldType, $insertValue, $newAnchor, $newHref, $newPosition);

        if (! ($insertResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => $insertResult['reason'] ?? 'unexpected',
                'blocking_href' => $insertResult['blocking_href'] ?? null,
                'message' => $this->messageForReason(
                    $insertResult['reason'] ?? 'anchor_not_found',
                    $insertResult['blocking_href'] ?? null,
                ),
            ];
        }

        $entry->set($removalField, $insertResult['content']);

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
                'message' => 'The entry has been modified in the meantime. Please close the modal and reopen it.',
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
     * Derive Step C's insert position from Step A's removal position
     * and the user's anchor edit. The new anchor must be a pure extension
     * (new contains original) OR a pure contraction (original contains new);
     * other edits — replacing the anchor entirely, character swaps — return
     * null so the caller surfaces an actionable message instead of silently
     * landing the link at the wrong place.
     *
     * For Bard/Replicator positions: keeps replicator_path + paragraph_path
     * intact, shifts char_start / char_end. For Markdown: shifts char_start
     * / char_end only.
     *
     * @param  array|null  $originalPosition  From UrlReplacer::replaceNth*
     * @return array|null  Same shape as input, with new char range, or null
     */
    protected function deriveNewPosition(?array $originalPosition, string $originalAnchor, string $newAnchor): ?array
    {
        if ($originalPosition === null) {
            return null;
        }

        $originalLen = mb_strlen($originalAnchor);
        $newLen = mb_strlen($newAnchor);
        $origStart = $originalPosition['char_start'] ?? null;
        if ($origStart === null) {
            return null;
        }

        // Determine shift: how does new anchor sit relative to original?
        $shift = null;
        // Case 1: new contains original (expansion — prefix or suffix or both).
        $posOfOrigInNew = mb_strpos($newAnchor, $originalAnchor);
        if ($posOfOrigInNew !== false) {
            $shift = -$posOfOrigInNew; // new starts $posOfOrigInNew chars BEFORE original
        } else {
            // Case 2: original contains new (shrink).
            $posOfNewInOrig = mb_strpos($originalAnchor, $newAnchor);
            if ($posOfNewInOrig !== false) {
                $shift = $posOfNewInOrig; // new starts $posOfNewInOrig chars AFTER original
            }
        }
        if ($shift === null) {
            return null;
        }

        $newStart = $origStart + $shift;
        $newEnd = $newStart + $newLen;
        if ($newStart < 0) {
            return null;
        }

        $newPosition = $originalPosition;
        $newPosition['char_start'] = $newStart;
        $newPosition['char_end'] = $newEnd;

        return $newPosition;
    }

    /**
     * Field-type-dispatched position-based insert. Returns the
     * BardLinkInserter::insertLinkAtPosition* shape:
     * `['ok' => bool, 'content' => ?mixed, 'reason' => ?string, 'blocking_href' => ?string]`.
     */
    protected function insertAtPosition(string $type, mixed $value, string $newAnchor, string $newHref, array $position): array
    {
        if ($type === 'bard') {
            return BardLinkInserter::insertLinkAtPositionInBard(
                $value, $newAnchor, $newHref,
                $position['paragraph_path'] ?? [],
                $position['char_start'] ?? 0,
                $position['char_end'] ?? 0,
            );
        }
        if ($type === 'replicator') {
            return BardLinkInserter::insertLinkAtPositionInReplicator(
                $value, $newAnchor, $newHref,
                $position['replicator_path'] ?? [],
                $position['paragraph_path'] ?? [],
                $position['char_start'] ?? 0,
                $position['char_end'] ?? 0,
            );
        }
        if ($type === 'markdown') {
            return BardLinkInserter::insertLinkAtPositionInMarkdown(
                $value, $newAnchor, $newHref,
                $position['char_start'] ?? 0,
                $position['char_end'] ?? 0,
            );
        }

        return ['ok' => false, 'reason' => 'unsupported_field_type'];
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
            'anchor_not_found' => 'The anchor text is no longer present in the entry.',
            'context_mismatch' => 'The original sentence context is no longer in the entry — please reopen the modal.',
            'crosses_existing_link' => $blockingLabel !== null
                ? "The anchor overlaps with an existing link to {$blockingLabel} in the same entry. Please remove that link first and try again."
                : 'The anchor overlaps with an existing link in the same entry. Please remove that link first.',
            'already_linked_to_target' => 'This text is already linked to the target.',
            'anchor_edit_not_supported' => 'The anchor edit is not a simple expansion or shortening — please delete the link and add it again.',
            'invalid_position', 'out_of_range', 'crosses_nontext_boundary' => 'The anchor could not be placed in the entry — please reopen the modal.',
            default => 'Re-link could not be performed.',
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
