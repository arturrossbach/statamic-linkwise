<?php

namespace Inkline\Linkwise\Support;

class ContextExtractor
{
    /**
     * Extract a context snippet with "..." truncation indicators (for display).
     *
     * @param  int  $occurrence  Which occurrence to find (0 = first, 1 = second, etc.)
     */
    public static function extract(string $text, string $anchorText, int $maxChars = 120, int $occurrence = 0): string
    {
        $result = static::extractStructured($text, $anchorText, $maxChars, $occurrence);

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
     * @return array{text: string, truncated_start: bool, truncated_end: bool}|null
     */
    public static function extractStructured(string $text, string $anchorText, int $maxChars = 120, int $occurrence = 0): ?array
    {
        if ($anchorText === '' || $text === '') {
            return null;
        }

        $pos = false;
        $offset = 0;
        $anchorLen = mb_strlen($anchorText);
        $textLength = mb_strlen($text);

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

        $halfWindow = (int) max(20, floor(($maxChars - $anchorLen) / 2));

        $start = max(0, $pos - $halfWindow);
        $end = min($textLength, $pos + $anchorLen + $halfWindow);

        // Snap to word boundaries
        if ($start > 0) {
            $spacePos = mb_strpos($text, ' ', $start);
            if ($spacePos !== false && $spacePos < $pos) {
                $start = $spacePos + 1;
            }
        }

        if ($end < $textLength) {
            $spacePos = mb_strrpos(mb_substr($text, 0, $end), ' ');
            if ($spacePos !== false && $spacePos > $pos + $anchorLen) {
                $end = $spacePos;
            }
        }

        return [
            'text' => trim(mb_substr($text, $start, $end - $start)),
            'truncated_start' => $start > 0,
            'truncated_end' => $end < $textLength,
        ];
    }
}
