<?php

namespace Arturrossbach\Linkwise\Support\Bard;

/**
 * Finds the FIRST valid character position to wrap a link mark around an
 * anchor inside a Bard/ProseMirror children array, OR returns the most
 * informative failure reason if no valid position exists.
 *
 * Extracted from {@see \Arturrossbach\Linkwise\Support\BardLinkInserter}
 * as the REV-OB-04 wurzel-walker (Bug-Klasse 16–20). The behavior of
 * {@see find()} is identical to the historical
 * BardLinkInserter::findValidMatchPosition; the priority ladder for
 * {@see pickWorseFailure()} is unchanged.
 *
 * Failure ranking (high → low severity, used both inside the walker and
 * by the aggregator that combines per-paragraph results):
 *
 *   crosses_existing_link > already_linked_to_target > context_mismatch > anchor_not_found
 *
 * Pinned by BardLinkInserterTest::test_dry_run_*ranking* characterization
 * tests. Any change to the ranking semantics here MUST extend those tests
 * first — see [[architectural_health]] Klasse 1.y.
 */
class AnchorPositionFinder
{
    /**
     * Walk a Bard children array and return the first valid wrap position
     * for the anchor, or a structured failure with the most-informative
     * reason among rejected occurrences.
     *
     * Parity-twin: {@see \Arturrossbach\Linkwise\Support\Markdown\MarkdownLinkInserter::insertLinkIntoMarkdown}
     * implements the equivalent walk for raw-markdown strings (same
     * needle-search loop, same word-boundary gate, same context-range
     * guard, but a different already-linked test that scans `[…](…)`
     * syntax instead of structured `link` marks). Any new gate or
     * failure-reason added here MUST land on both sides — Klasse-1
     * pattern in [[architectural_health]].
     *
     * @param  array  $children  Bard children (text + non-text nodes mixed)
     * @param  string  $anchorText  Plain anchor string to find
     * @param  string  $href  Target URL — only used to distinguish
     *                        "crosses_existing_link" from "already_linked_to_target".
     * @param  bool  $caseSensitive  Match case strictly (default: false).
     * @param  string|null  $expectedSentenceContext  When the caller knows the
     *         sentence the anchor was scanned in, restrict the wrap to a
     *         character range containing that sentence. Stale records that
     *         no longer match yield ['ok' => false, 'reason' => 'context_mismatch'].
     * @return array{ok:bool, pos?:int, startNodeIndex?:int, endNodeIndex?:int,
     *               startOffset?:int, endOffset?:int, reason?:string, blocking_href?:string}
     */
    public static function find(
        array $children,
        string $anchorText,
        string $href,
        bool $caseSensitive = false,
        ?string $expectedSentenceContext = null
    ): array {
        // Build concatenated text from child text nodes
        $fullText = '';
        $nodeMap = []; // Maps char offset to [childIndex, offsetInNode]

        foreach ($children as $i => $child) {
            if (($child['type'] ?? '') !== 'text' || ! isset($child['text'])) {
                // Non-text node acts as a boundary
                $fullText .= "\0";
                $nodeMap[] = ['index' => -1, 'offset' => 0];

                continue;
            }

            $text = $child['text'];
            $len = mb_strlen($text);

            for ($c = 0; $c < $len; $c++) {
                $nodeMap[] = ['index' => $i, 'offset' => $c];
            }

            $fullText .= $text;
        }

        // Context-fingerprint guard: when the caller knows the sentence the
        // anchor was scanned in, the wrap MUST land at a position whose
        // surrounding text contains that sentence. If the entry now has a
        // SECOND occurrence of the anchor (e.g. user prepended one), the
        // naive "wrap first match" would silently mutate the wrong one.
        // We compute the allowed character range here once; positions
        // outside it are rejected below.
        $contextRange = null;
        if ($expectedSentenceContext !== null && $expectedSentenceContext !== '') {
            // The scan often returns sentence-context with a leading "…" /
            // ellipsis (ContextExtractor truncation). Strip those before
            // matching so a literal substring search lines up.
            $needle = trim(str_replace(['…', '...'], '', $expectedSentenceContext));
            // Defense-in-depth: stale records may carry multi-line context
            // (cross-paragraph blob from a buggy older extractContext build).
            // Narrow to the line containing the anchor so the search can
            // succeed inside a single paragraph's $fullText.
            $needle = static::narrowContextToAnchorLine($needle, $anchorText);
            if ($needle !== '' && mb_strlen($needle) >= mb_strlen($anchorText)) {
                $rangeStart = mb_stripos($fullText, $needle);
                if ($rangeStart === false) {
                    // Sentence not present in current content → scan is
                    // stale, refuse to wrap anything. Caller surfaces this
                    // as "context changed, refresh and retry".
                    return ['ok' => false, 'reason' => 'context_mismatch'];
                }
                $contextRange = ['start' => $rangeStart, 'end' => $rangeStart + mb_strlen($needle)];
            }
        }

        $anchorLen = mb_strlen($anchorText);
        $offset = 0;
        $bestFailure = null; // most-informative failure reason seen so far

        // Walk all occurrences — accept the first that sits at a word boundary,
        // doesn't cross a non-text-node marker, sits inside the context range,
        // and isn't already linked. "database" must skip "databases" and hit
        // the standalone "Database" next.
        while (true) {
            $found = $caseSensitive
                ? mb_strpos($fullText, $anchorText, $offset)
                : mb_stripos($fullText, $anchorText, $offset);

            if ($found === false) {
                // Exhausted occurrences — surface best failure tracked, or
                // anchor_not_found if every occurrence was rejected at the
                // word-boundary / null-byte gate (= no real anchor present).
                return $bestFailure ?? ['ok' => false, 'reason' => 'anchor_not_found'];
            }

            // Word-boundary + cross-null-byte gates mean "not a real
            // occurrence" → don't update bestFailure, just advance.
            if (! static::isAtWordBoundary($fullText, $found, $anchorLen)) {
                $offset = $found + $anchorLen;

                continue;
            }
            if (str_contains(mb_substr($fullText, $found, $anchorLen), "\0")) {
                $offset = $found + $anchorLen;

                continue;
            }

            // Context-range check — outside-range matches are a different
            // occurrence than the one the scan captured. Record as
            // context_mismatch and keep walking; a later occurrence might
            // pass or yield a stronger reason (crosses_existing_link).
            if ($contextRange !== null
                && ($found < $contextRange['start'] || $found + $anchorLen > $contextRange['end'])) {
                if ($bestFailure === null) {
                    $bestFailure = ['ok' => false, 'reason' => 'context_mismatch'];
                }
                $offset = $found + $anchorLen;

                continue;
            }

            $startMap = $nodeMap[$found];
            $endMap = $nodeMap[$found + $anchorLen - 1];

            if ($startMap['index'] === -1 || $endMap['index'] === -1) {
                // Anchor straddles a non-text boundary marker — same severity
                // as no occurrence.
                $offset = $found + $anchorLen;

                continue;
            }

            // Already-linked guard — REFUSE to mutate any text node that
            // already carries a link mark, regardless of href.
            //
            // History:
            // - Bug B (2026-05-08): partial-overlap split would tear an
            //   existing link apart ("Brauner-Zucker-Speck-Kekse"
            //   → "Brauner"=NEW + "-Zucker-Speck-Kekse"=OLD). Fixed for
            //   partial overlaps only — fully-covered matches still ran
            //   a "URL upgrade" that silently replaced the href.
            // - 2026-05-10: insert-parity audit + user feedback — silent
            //   URL-upgrade is the same bug-class as silent wrong-link
            //   unlink. USP is "kein silent overwrite". ANY existing link
            //   mark on an affected node = skip. Power-user wanting to
            //   remap an anchor uses URL-Changer to remove the old links
            //   first, then re-runs the rule. Two explicit steps, no
            //   surprise data loss.
            //
            // Bug 17 (2026-05-11): we now also capture the blocking href
            // so RelinkService can name it in an actionable
            // error message ("Anchor überlappt mit Link auf 'X'") rather
            // than a generic "would fail".
            //
            // When the anchor span crosses MULTIPLE link marks (the Bug 17
            // Repro A shape — anchor expanded to cover an existing
            // different-target link adjacent to a same-target one), prefer
            // the DIFFERENT-target href as the blocker: that's the actual
            // conflict the user has to resolve, not the same-target no-op
            // they'd implicitly clear via Step 1 anyway.
            $startIdx = $startMap['index'];
            $endIdx = $endMap['index'];
            $blockingHref = null;
            $sameTargetHref = null;

            for ($idx = $startIdx; $idx <= $endIdx; $idx++) {
                $child = $children[$idx];
                foreach ($child['marks'] ?? [] as $m) {
                    if (($m['type'] ?? '') !== 'link') {
                        continue;
                    }
                    $thisHref = $m['attrs']['href'] ?? '';

                    if ($thisHref !== $href) {
                        // Different target — strongest blocker, stop early.
                        $blockingHref = $thisHref;
                        break 2;
                    }
                    if ($sameTargetHref === null) {
                        $sameTargetHref = $thisHref;
                    }
                }
            }

            // Fall back to same-target only when no different-target mark
            // was seen across the span.
            if ($blockingHref === null) {
                $blockingHref = $sameTargetHref;
            }

            if ($blockingHref !== null) {
                $reason = ($blockingHref === $href) ? 'already_linked_to_target' : 'crosses_existing_link';
                $candidate = ['ok' => false, 'reason' => $reason, 'blocking_href' => $blockingHref];

                // crosses_existing_link is the strongest reason — it
                // overrides every weaker one. already_linked_to_target
                // beats context_mismatch / anchor_not_found, but loses to
                // a different-href crosses-link in case both occurrences
                // exist (we want to name the conflict, not the no-op).
                $currentReason = $bestFailure['reason'] ?? null;
                if ($currentReason === null
                    || $currentReason === 'anchor_not_found'
                    || $currentReason === 'context_mismatch'
                    || ($currentReason === 'already_linked_to_target' && $reason === 'crosses_existing_link')) {
                    $bestFailure = $candidate;
                }

                $offset = $found + $anchorLen;

                continue;
            }

            // All checks passed — valid match
            return [
                'ok' => true,
                'pos' => $found,
                'startNodeIndex' => $startIdx,
                'endNodeIndex' => $endIdx,
                'startOffset' => $startMap['offset'],
                'endOffset' => $endMap['offset'] + 1,
            ];
        }
    }

    /**
     * Severity comparator for failure reasons across aggregated walker
     * results (per-paragraph aggregation in BardLinkInserter's dry-run
     * + replicator walk).
     *
     * Severity (high → low):
     *   crosses_existing_link > already_linked_to_target > context_mismatch > anchor_not_found
     *
     * Rationale: a "crosses_existing_link" against a DIFFERENT target is
     * the most actionable surface (toast names the blocking link).
     * "already_linked_to_target" is a no-op vs an error semantically;
     * we still surface it so the user sees they're not getting a fresh
     * wrap, but it loses to a crosses-link finding elsewhere in the
     * entry. "context_mismatch" beats "anchor_not_found" because it's
     * more specific (anchor exists somewhere, just not in the captured
     * sentence's range).
     *
     * @param  array{ok:bool, reason?:string, blocking_href?:string}|null  $current
     * @param  array{ok:bool, reason?:string, blocking_href?:string}  $candidate
     * @return array{ok:bool, reason?:string, blocking_href?:string}
     */
    public static function pickWorseFailure(?array $current, array $candidate): array
    {
        if ($current === null) {
            return $candidate;
        }
        $rank = [
            'anchor_not_found' => 0,
            'context_mismatch' => 1,
            'already_linked_to_target' => 2,
            'crosses_existing_link' => 3,
        ];
        $rankCurrent = $rank[$current['reason'] ?? ''] ?? 0;
        $rankCandidate = $rank[$candidate['reason'] ?? ''] ?? 0;

        return $rankCandidate > $rankCurrent ? $candidate : $current;
    }

    /**
     * When the captured sentence-context blob spans multiple lines
     * (older buggy ContextExtractor builds saved cross-paragraph text),
     * narrow it to the line that actually contains the anchor so a
     * substring search inside a single paragraph's text can succeed.
     *
     * If the anchor doesn't appear in any line, return the needle
     * unchanged — the caller's mb_stripos returns false and refuses to
     * wrap (no silent fallback to a different paragraph).
     */
    public static function narrowContextToAnchorLine(string $needle, string $anchorText): string
    {
        if ($needle === '' || ! str_contains($needle, "\n")) {
            return $needle;
        }

        $lines = explode("\n", $needle);
        foreach ($lines as $line) {
            if (mb_stripos($line, $anchorText) !== false) {
                return trim($line);
            }
        }

        // Anchor isn't in any line: leave needle unchanged so the caller's
        // mb_stripos returns false → return null → no silent wrap.
        return $needle;
    }

    /**
     * Word-boundary check on a UTF-8 string: the character immediately
     * before AND after the match must not be a letter or digit
     * (Unicode-aware \p{L}\p{N}). Used by the Bard walker AND the
     * Markdown insert paths in BardLinkInserter; exposed as public so
     * those callers don't need to reach into the walker class.
     */
    public static function isAtWordBoundary(string $text, int $pos, int $length): bool
    {
        // Check character before the match
        if ($pos > 0) {
            $before = mb_substr($text, $pos - 1, 1);
            if (preg_match('/[\p{L}\p{N}]/u', $before)) {
                return false;
            }
        }

        // Check character after the match
        $afterPos = $pos + $length;
        if ($afterPos < mb_strlen($text)) {
            $after = mb_substr($text, $afterPos, 1);
            if (preg_match('/[\p{L}\p{N}]/u', $after)) {
                return false;
            }
        }

        return true;
    }
}
