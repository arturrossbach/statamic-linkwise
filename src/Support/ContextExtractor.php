<?php

namespace Arturrossbach\Linkwise\Support;

class ContextExtractor
{
    /**
     * Extract a context snippet with "..." truncation indicators (for display).
     *
     * @param  int  $occurrence  Which occurrence to find (0 = first, 1 = second, etc.)
     */
    public static function extract(string $text, string $anchorText, int $maxChars = 240, int $occurrence = 0, bool $clampToParagraph = true): string
    {
        $result = static::extractStructured($text, $anchorText, $maxChars, $occurrence, $clampToParagraph);

        if ($result === null) {
            return '';
        }

        $prefix = $result['truncated_start'] ? '...' : '';
        $suffix = $result['truncated_end'] ? '...' : '';

        return $prefix.$result['text'].$suffix;
    }

    /**
     * Extract a context snippet as structured data (clean text + truncation flags).
     *
     * Naive occurrence-counter variant: finds the n-th string match of $anchorText
     * regardless of link state. Use this only when the caller has no offset info —
     * e.g. preview snippets where the text isn't tied to a specific Bard link mark.
     * For real link positions, use {@see extractAtOffset()} directly; mixing the
     * counter with mixed linked/unlinked anchors (Bug 2026-05-11) silently picks
     * the wrong occurrence.
     *
     * @return array{text: string, truncated_start: bool, truncated_end: bool}|null
     */
    public static function extractStructured(string $text, string $anchorText, int $maxChars = 240, int $occurrence = 0, bool $clampToParagraph = true): ?array
    {
        if ($anchorText === '' || $text === '') {
            return null;
        }

        $pos = false;
        $offset = 0;
        $anchorLen = mb_strlen($anchorText);

        for ($i = 0; $i <= $occurrence; $i++) {
            $pos = mb_stripos($text, $anchorText, $offset);
            if ($pos === false) {
                break;
            }
            if ($i < $occurrence) {
                $offset = $pos + $anchorLen;
            }
        }

        if ($pos === false) {
            return null;
        }

        return static::extractAtOffset($text, $pos, $anchorLen, $maxChars, $clampToParagraph);
    }

    /**
     * Extract a context snippet at a known offset.
     *
     * Use this when the caller already knows the exact character position of the
     * anchor — typically a Bard/Markdown link extractor that walked the source
     * tree and noted where each link sits in the flat text. Bypasses the brittle
     * occurrence-counter path entirely: no string-matching, no off-by-one if the
     * anchor word also appears unlinked elsewhere in the text.
     *
     * @return array{text: string, truncated_start: bool, truncated_end: bool}|null
     */
    public static function extractAtOffset(string $text, int $offset, int $anchorLen, int $maxChars = 240, bool $clampToParagraph = true): ?array
    {
        if ($text === '' || $offset < 0 || $anchorLen <= 0) {
            return null;
        }

        $textLength = mb_strlen($text);
        if ($offset >= $textLength) {
            return null;
        }

        $halfWindow = (int) max(20, floor(($maxChars - $anchorLen) / 2));

        $start = max(0, $offset - $halfWindow);
        $end = min($textLength, $offset + $anchorLen + $halfWindow);

        // Hard-stop at paragraph boundary ("\n") — ONLY when the caller
        // needs the snippet to round-trip through BardLinkInserter as
        // `expectedSentenceContext` (silent "Anchor text not found" if
        // context spans a paragraph break). Display-only callers (links
        // modal, broken-links report) should set $clampToParagraph=false
        // to get more surrounding context for very short paragraphs
        // (User-Smoke 2026-05-21: "mit einem gekühlten Weißwein."
        // alone shown for an entry's link).
        //
        // SuggestionEngine::extractContext has its own independent
        // paragraph-clamp (line 1069-1075) so suggestion-match paths
        // do NOT depend on this clamp.
        if ($clampToParagraph) {
            $textBeforeAnchor = mb_substr($text, 0, $offset);
            $lastNl = mb_strrpos($textBeforeAnchor, "\n");
            if ($lastNl !== false) {
                $start = max($start, $lastNl + 1);
            }
            $nextNl = mb_strpos($text, "\n", $offset + $anchorLen);
            if ($nextNl !== false) {
                $end = min($end, $nextNl);
            }
        }

        // Snap to word boundaries
        if ($start > 0) {
            $spacePos = mb_strpos($text, ' ', $start);
            if ($spacePos !== false && $spacePos < $offset) {
                $start = $spacePos + 1;
            }
        }

        if ($end < $textLength) {
            $spacePos = mb_strrpos(mb_substr($text, 0, $end), ' ');
            if ($spacePos !== false && $spacePos > $offset + $anchorLen) {
                $end = $spacePos;
            }
        }

        $rawSnippet = mb_substr($text, $start, $end - $start);
        $leftStripped = mb_strlen($rawSnippet) - mb_strlen(ltrim($rawSnippet));

        return [
            'text' => trim($rawSnippet),
            'truncated_start' => $start > 0,
            'truncated_end' => $end < $textLength,
            // Position of the anchor inside the returned 'text'. Frontends
            // that highlight the anchor must NOT fall back to indexOf when
            // the same word appears multiple times in the snippet (e.g.
            // unlinked + linked in the same paragraph) — they'd colour the
            // wrong occurrence (Bug 2026-05-11).
            'anchor_offset' => max(0, $offset - $start - $leftStripped),
        ];
    }
}
