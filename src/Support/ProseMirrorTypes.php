<?php

namespace Arturrossbach\Linkwise\Support;

class ProseMirrorTypes
{
    public const NODE_TYPES = [
        'paragraph', 'heading', 'text', 'bulletList', 'orderedList',
        'listItem', 'blockquote', 'codeBlock', 'hardBreak', 'horizontalRule',
        'table', 'tableRow', 'tableCell', 'tableHeader', 'set',
    ];

    public static function looksLikeBardContent(array $value): bool
    {
        $first = reset($value);

        if (! is_array($first) || ! isset($first['type'])) {
            return false;
        }

        return in_array($first['type'], self::NODE_TYPES, true);
    }
}
