<?php

namespace Arturrossbach\Linkwise\Support;

/**
 * Classifies a SINGLE occurrence of a keyword as one of:
 *   - 'linked_to_target'  — already wrapped in a link to the rule's target
 *   - 'linked_elsewhere'  — wrapped in a link to a different URL
 *   - 'would_link'        — plain text, ready to be linked
 *
 * Per-occurrence classification matters in multi-mode (oncePerPost=false)
 * preview, where two matches in the same entry can have different status
 * (the first already linked, the second plain text). Entry-level checks
 * paint both with the same brush and mislead the user.
 */
class OccurrenceStatus
{
    /**
     * Classify an occurrence inside a Markdown string at a given character position.
     */
    public static function forMarkdownPosition(string $markdown, int $charPos, int $length, string $targetHref): string
    {
        // Find every existing markdown link and its character range.
        $linkPattern = '/\[([^\]]*)\]\(([^\)]+)\)/u';
        if (! preg_match_all($linkPattern, $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            return 'would_link';
        }

        foreach ($matches[0] as $i => [$fullMatch, $byteOffset]) {
            // preg_match_all returns byte offsets — convert to char offsets for mb-safe comparison.
            $startChar = mb_strlen(substr($markdown, 0, $byteOffset));
            $endChar = $startChar + mb_strlen($fullMatch);

            if ($charPos >= $startChar && $charPos + $length <= $endChar) {
                $url = $matches[2][$i][0];

                return $url === $targetHref ? 'linked_to_target' : 'linked_elsewhere';
            }
        }

        return 'would_link';
    }

    /**
     * Classify an occurrence inside a Bard text node based on its link marks.
     */
    public static function forBardTextNode(array $textNode, string $targetHref): string
    {
        if (empty($textNode['marks']) || ! is_array($textNode['marks'])) {
            return 'would_link';
        }

        foreach ($textNode['marks'] as $mark) {
            if (($mark['type'] ?? '') !== 'link') {
                continue;
            }
            $href = $mark['attrs']['href'] ?? '';

            return $href === $targetHref ? 'linked_to_target' : 'linked_elsewhere';
        }

        return 'would_link';
    }
}
