<?php

namespace Arturrossbach\Linkwise\Support\Markdown;

use Arturrossbach\Linkwise\Support\Bard\AnchorPositionFinder;

/**
 * Markdown-string link-inserter primitives — extracted from
 * {@see \Arturrossbach\Linkwise\Support\BardLinkInserter} as part of
 * the REV-OB-03 god-class split (Sprint 4 Part 3 Phase A).
 *
 * BardLinkInserter retains thin delegation methods of the same names so
 * the package-public API (27+ external callers of `insertLinkIntoMarkdown`)
 * does not break.
 *
 * Word-boundary + context-narrowing logic is shared with the Bard walker
 * via {@see AnchorPositionFinder}.
 */
class MarkdownLinkInserter
{
    /**
     * Single-insert: wrap the FIRST valid unlinked occurrence of $anchorText
     * with `[anchor](href)`. Returns null if no valid occurrence exists.
     *
     * Honors the same gates the Bard walker does:
     *   - word-boundary on both ends (\p{L}\p{N})
     *   - skip occurrences sitting inside an existing `[…](…)` link (both
     *     anchor and URL portion are off-limits — prevents nested
     *     `[[anchor](url)](url)` corruption)
     *   - context-fingerprint range (when $expectedSentenceContext is set)
     *     with NLP-flatten fallback for sentences captured adjacent to
     *     existing markdown links
     *   - bail if anchor is already linked anywhere in the markdown
     */
    public static function insertLinkIntoMarkdown(string $markdown, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?string
    {
        // Check if anchor text is already linked in Markdown
        $escapedAnchor = preg_quote($anchorText, '/');
        $pattern = '/\['.$escapedAnchor.'\]\(/'.($caseSensitive ? '' : 'i');
        if (preg_match($pattern, $markdown)) {
            return null; // Already linked
        }

        // Build skip-ranges for existing markdown links — both the anchor
        // text and the URL portion are off-limits. Without this, a candidate
        // matching a substring of an existing link's anchor (e.g. "development"
        // landing inside `[Modern web development](url)`) or its URL portion
        // (e.g. "statamic" landing inside `statamic://entry::uuid`) would
        // silently corrupt the content with nested `[[anchor]](url)](url)`
        // syntax. Same pattern as insertAllLinksIntoMarkdown — single-insert
        // path was missing it, the multi-insert path always had it.
        $skipRanges = [];
        if (preg_match_all('/\[[^\]]*\]\([^\)]+\)/u', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $byteOffset]) {
                $charOffset = mb_strlen(substr($markdown, 0, $byteOffset));
                $skipRanges[] = [$charOffset, $charOffset + mb_strlen($text)];
            }
        }

        // Context-fingerprint guard — see findAndLinkInChildren for full
        // rationale. When the scan captured the anchor in a specific
        // sentence, the wrap MUST land inside that sentence's range,
        // not on a different occurrence of the same anchor.
        $contextRange = null;
        if ($expectedSentenceContext !== null && $expectedSentenceContext !== '') {
            $needle = trim(str_replace(['…', '...'], '', $expectedSentenceContext));
            // Defense-in-depth: stale records may carry multi-line context
            // (cross-paragraph blob from a buggy older extractContext build).
            // Narrow to the line containing the anchor so the search can
            // succeed at all; otherwise mb_stripos returns false and the
            // wrap silently skips. New contexts (post-fix extractContext)
            // are already single-line and pass through unchanged.
            $needle = AnchorPositionFinder::narrowContextToAnchorLine($needle, $anchorText);
            if ($needle !== '' && mb_strlen($needle) >= mb_strlen($anchorText)) {
                $rangeStart = mb_stripos($markdown, $needle);
                if ($rangeStart !== false) {
                    $contextRange = ['start' => $rangeStart, 'end' => $rangeStart + mb_strlen($needle)];
                } else {
                    // Fallback (Bug 2026-05-11): SuggestionEngine produces
                    // sentence_context from the NLP-flattened text (markdown
                    // links collapsed to their anchor text). When the
                    // captured sentence sits adjacent to a markdown link
                    // (e.g. "...[einem gekühlten Weißwein](url). CMS-Migration!"),
                    // the raw-markdown haystack contains the link syntax,
                    // and a literal mb_stripos for the flat needle returns
                    // false. Flatten the markdown, find the needle there,
                    // map flat-position back to raw-markdown position.
                    [$flat, $rawToFlat] = static::flattenMarkdownLinks($markdown);
                    $flatStart = mb_stripos($flat, $needle);
                    if ($flatStart === false) {
                        return null; // truly absent
                    }
                    $flatEnd = $flatStart + mb_strlen($needle);
                    $rawStart = null;
                    $rawEnd = null;
                    foreach ($rawToFlat as $rawIdx => $flatIdx) {
                        if ($flatIdx === null) {
                            continue;
                        }
                        if ($rawStart === null && $flatIdx >= $flatStart) {
                            $rawStart = $rawIdx;
                        }
                        if ($flatIdx < $flatEnd) {
                            $rawEnd = $rawIdx + 1;
                        }
                    }
                    if ($rawStart === null || $rawEnd === null) {
                        return null;
                    }
                    $contextRange = ['start' => $rawStart, 'end' => $rawEnd];
                }
            }
        }

        $anchorLen = mb_strlen($anchorText);
        $offset = 0;

        // Walk through all occurrences. Return on the first one that sits at a word boundary
        // (so "database" skips "databases" and hits the standalone "Database" next)
        // AND is not inside an existing markdown link.
        while (true) {
            $pos = $caseSensitive
                ? mb_strpos($markdown, $anchorText, $offset)
                : mb_stripos($markdown, $anchorText, $offset);

            if ($pos === false) {
                return null;
            }

            if (AnchorPositionFinder::isAtWordBoundary($markdown, $pos, $anchorLen)) {
                $inSkipRange = false;
                foreach ($skipRanges as [$start, $end]) {
                    if ($pos >= $start && $pos < $end) {
                        $inSkipRange = true;
                        break;
                    }
                }

                $outsideContextRange = $contextRange !== null
                    && ($pos < $contextRange['start'] || $pos + $anchorLen > $contextRange['end']);

                if (! $inSkipRange && ! $outsideContextRange) {
                    $actualText = mb_substr($markdown, $pos, $anchorLen);
                    $before = mb_substr($markdown, 0, $pos);
                    $after = mb_substr($markdown, $pos + $anchorLen);

                    return $before.'['.$actualText.']('.$href.')'.$after;
                }
            }

            $offset = $pos + $anchorLen;
        }
    }

    /**
     * Multi-insert variant: wrap EVERY valid unlinked occurrence with a link.
     * Returns null if no insertion was made.
     */
    public static function insertAllLinksIntoMarkdown(string $markdown, string $anchorText, string $href, bool $caseSensitive = false): ?string
    {
        $anchorLen = mb_strlen($anchorText);
        if ($anchorLen === 0) {
            return null;
        }

        // Build a set of byte-ranges that are inside existing markdown links —
        // we skip occurrences inside those so we don't double-wrap.
        $skipRanges = [];
        if (preg_match_all('/\[[^\]]*\]\([^\)]+\)/u', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $byteOffset]) {
                // Convert byte offset to mb char offset for consistency with mb_substr
                $charOffset = mb_strlen(substr($markdown, 0, $byteOffset));
                $skipRanges[] = [$charOffset, $charOffset + mb_strlen($text)];
            }
        }

        $offset = 0;
        $inserts = [];

        while (true) {
            $pos = $caseSensitive
                ? mb_strpos($markdown, $anchorText, $offset)
                : mb_stripos($markdown, $anchorText, $offset);

            if ($pos === false) {
                break;
            }

            if (! AnchorPositionFinder::isAtWordBoundary($markdown, $pos, $anchorLen)) {
                $offset = $pos + $anchorLen;

                continue;
            }

            // Skip if this position is inside an existing markdown link
            $inSkipRange = false;
            foreach ($skipRanges as [$start, $end]) {
                if ($pos >= $start && $pos < $end) {
                    $inSkipRange = true;
                    break;
                }
            }

            if ($inSkipRange) {
                $offset = $pos + $anchorLen;

                continue;
            }

            $actualText = mb_substr($markdown, $pos, $anchorLen);
            $inserts[] = [$pos, $anchorLen, '['.$actualText.']('.$href.')'];
            $offset = $pos + $anchorLen;
        }

        if (empty($inserts)) {
            return null;
        }

        // Apply right-to-left so positions stay valid
        $result = $markdown;
        foreach (array_reverse($inserts) as [$pos, $len, $replacement]) {
            $result = mb_substr($result, 0, $pos).$replacement.mb_substr($result, $pos + $len);
        }

        return $result;
    }

    /**
     * Insert a link at a position inside a Markdown string.
     *
     * @return array{ok: bool, content?: string, reason?: string, blocking_href?: string}
     */
    public static function insertLinkAtPositionInMarkdown(string $markdown, string $anchorText, string $href, int $charStart, int $charEnd): array
    {
        if ($charStart < 0 || $charEnd <= $charStart || $charEnd > strlen($markdown)) {
            return ['ok' => false, 'reason' => 'invalid_position'];
        }

        // Already-linked guard: if the [charStart..charEnd] range sits inside
        // an existing `[anchor](url)`, refuse. Lightweight check: scan all
        // `[…](…)` matches with PREG_OFFSET_CAPTURE; if any overlaps, refuse.
        if (preg_match_all('#\[[^\[\]]*\]\([^)]+\)#', $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $mStart = $m[0][1];
                $mEnd = $mStart + strlen($m[0][0]);
                if (! ($charEnd <= $mStart || $charStart >= $mEnd)) {
                    // Overlap. Extract href.
                    if (preg_match('#\]\(([^)]+)\)#', $m[0][0], $hm)) {
                        $reason = $hm[1] === $href ? 'already_linked_to_target' : 'crosses_existing_link';

                        return ['ok' => false, 'reason' => $reason, 'blocking_href' => $hm[1]];
                    }

                    return ['ok' => false, 'reason' => 'crosses_existing_link'];
                }
            }
        }

        $anchor = substr($markdown, $charStart, $charEnd - $charStart);
        $replacement = '['.$anchor.']('.$href.')';
        $result = substr_replace($markdown, $replacement, $charStart, $charEnd - $charStart);

        return ['ok' => true, 'content' => $result];
    }

    /**
     * Build a "flat" version of markdown where [anchor](url) collapses to
     * just `anchor`, plus a position map raw_offset → flat_offset (or null
     * when the raw char sits inside a [...](...) syntax fragment that's
     * not in the flat string). Used by the insertLinkIntoMarkdown context
     * guard so a sentence-context that SuggestionEngine generated from
     * the NLP-flattened text can still find its anchor occurrence inside
     * raw markdown that contains inline links (Bug 2026-05-11).
     *
     * @return array{0: string, 1: array<int, int|null>}
     */
    protected static function flattenMarkdownLinks(string $markdown): array
    {
        $flat = '';
        $rawToFlat = [];
        $rawLen = mb_strlen($markdown);
        $i = 0;
        while ($i < $rawLen) {
            $remaining = mb_substr($markdown, $i);
            // Match [anchor](url) starting at the current cursor.
            if (preg_match('/^\[([^\[\]]*)\]\(([^)]+)\)/u', $remaining, $m)) {
                $anchor = $m[1];
                $anchorLen = mb_strlen($anchor);
                $totalMatchLen = mb_strlen($m[0]);
                // The opening '[' — not in flat.
                $rawToFlat[$i] = null;
                // Anchor chars: appended to flat, each mapped.
                $flatStart = mb_strlen($flat);
                $flat .= $anchor;
                for ($k = 0; $k < $anchorLen; $k++) {
                    $rawToFlat[$i + 1 + $k] = $flatStart + $k;
                }
                // ']', '(', URL chars, ')' — all not in flat.
                $tailStart = $i + 1 + $anchorLen;
                $tailLen = $totalMatchLen - 1 - $anchorLen;
                for ($k = 0; $k < $tailLen; $k++) {
                    $rawToFlat[$tailStart + $k] = null;
                }
                $i += $totalMatchLen;

                continue;
            }
            // Regular char: copy to flat 1:1.
            $rawToFlat[$i] = mb_strlen($flat);
            $flat .= mb_substr($markdown, $i, 1);
            $i++;
        }

        return [$flat, $rawToFlat];
    }
}
