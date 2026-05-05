<?php

namespace Inkline\Linkwise\Support;

class TextExtractor
{
    /**
     * Extract plain text from Bard (ProseMirror) JSON content.
     *
     * @param  array  $bardContent  Array of ProseMirror nodes
     */
    public static function fromBard(array $bardContent): string
    {
        $texts = [];

        foreach ($bardContent as $node) {
            $text = static::extractTextFromNode($node);

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return implode("\n", $texts);
    }

    /**
     * Extract internal link target entry IDs from Bard content.
     *
     * @return string[] Array of entry IDs
     */
    public static function linksFromBard(array $bardContent): array
    {
        $links = [];

        foreach ($bardContent as $node) {
            static::extractLinksFromNode($node, $links);
        }

        return array_unique($links);
    }

    /**
     * Extract internal links with anchor text from Bard content.
     *
     * @return array<array{entry_id: string, anchor_text: string, href: string}>
     */
    public static function internalLinksWithAnchorFromBard(array $bardContent): array
    {
        $links = [];

        foreach ($bardContent as $node) {
            static::extractInternalLinksWithAnchorFromNode($node, $links);
        }

        return $links;
    }

    /**
     * Extract internal link entry IDs from Markdown content.
     *
     * @return string[]  Array of entry IDs
     */
    public static function linksFromMarkdown(string $markdown): array
    {
        $links = [];

        // Match [text](statamic://entry::uuid)
        if (preg_match_all('#\[[^\[\]]*\]\(statamic://entry::([^)]+)\)#', $markdown, $matches)) {
            $links = $matches[1];
        }

        return array_unique($links);
    }

    /**
     * Extract external links from Markdown content.
     *
     * @return array<array{url: string, anchor_text: string}>
     */
    public static function externalLinksFromMarkdown(string $markdown): array
    {
        $links = [];

        // Match [text](https://...) or [text](http://...) or [text](www....)
        if (preg_match_all('#\[([^\[\]]*)\]\(((?:https?://|www\.)[^)]+)\)#i', $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $links[] = [
                    'url' => $match[2],
                    'anchor_text' => $match[1],
                ];
            }
        }

        return $links;
    }

    /**
     * Extract external (HTTP/HTTPS) links from Bard content.
     *
     * @return array<array{url: string, anchor_text: string}> Array of external link records
     */
    public static function externalLinksFromBard(array $bardContent): array
    {
        $links = [];

        foreach ($bardContent as $node) {
            static::extractExternalLinksFromNode($node, $links);
        }

        return $links;
    }

    protected static function extractTextFromNode(array $node): string
    {
        $type = $node['type'] ?? '';

        // Bard SET (custom replicator block embedded inside the Bard tree —
        // pull_quote, buttons, code, image_caption, etc.). Previously this
        // path returned empty and discarded ALL set content: pull-quote
        // text, button labels, captions, anything custom-fielded inside a
        // Bard set was invisible to indexing/anchor matching. Walk
        // attrs.values like the EntryFieldWalker walks Replicator sets:
        // string fields with content go to the text pool, nested Bard
        // trees recurse, metadata/UUID/numeric/boolean shapes drop.
        if ($type === 'set') {
            $values = $node['attrs']['values'] ?? null;
            if (! is_array($values)) {
                return '';
            }

            $parts = [];
            foreach ($values as $key => $val) {
                if ($key === 'type' || $key === 'enabled' || $key === 'id') {
                    continue;
                }

                if (is_string($val)) {
                    // Single source of truth for "user content vs
                    // metadata/asset/UUID" lives in InsertableContentFilter.
                    // Bard custom-set keys are matched against the asset-
                    // handle blacklist so 'image: photo.jpg' or
                    // 'src: /cover.png' don't leak into the text pool.
                    if (! InsertableContentFilter::isContent($val, (string) $key)) {
                        continue;
                    }
                    $parts[] = trim($val);
                    continue;
                }

                if (is_array($val) && ! empty($val)) {
                    // Nested Bard tree (e.g. a custom set whose value field
                    // is itself a Bard editor). Recurse via fromBard so all
                    // nested paragraph/heading text is captured.
                    if (isset($val[0]['type'])) {
                        $sub = static::fromBard($val);
                        if ($sub !== '') $parts[] = $sub;
                    }
                }
            }

            return implode(' ', $parts);
        }

        // Text node — return its text content
        if (isset($node['text'])) {
            return $node['text'];
        }

        // Node with children — recurse
        if (isset($node['content']) && is_array($node['content'])) {
            $parts = [];

            foreach ($node['content'] as $child) {
                $text = static::extractTextFromNode($child);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            // Block-level nodes need space separation to prevent text merging
            // (e.g., table cells, list items, paragraphs within a container)
            $blockTypes = ['table', 'tableRow', 'tableCell', 'tableHeader', 'bulletList', 'orderedList', 'listItem', 'blockquote'];
            $separator = in_array($type, $blockTypes, true) ? ' ' : '';

            return implode($separator, $parts);
        }

        return '';
    }

    /**
     * Extract internal links from a node, merging adjacent text nodes with the same link href.
     */
    protected static function extractInternalLinksWithAnchorFromNode(array $node, array &$links): void
    {
        if (! isset($node['content']) || ! is_array($node['content'])) {
            return;
        }

        $currentHref = null;
        $currentAnchor = '';

        foreach ($node['content'] as $child) {
            $href = static::getInternalLinkHref($child);

            if ($href !== null && $href === $currentHref) {
                $currentAnchor .= $child['text'] ?? '';
            } else {
                // Flush previous link
                if ($currentHref !== null && $currentAnchor !== '') {
                    if (preg_match('#^statamic://entry::(.+)$#', $currentHref, $m)) {
                        $links[] = [
                            'entry_id' => $m[1],
                            'anchor_text' => $currentAnchor,
                            'href' => $currentHref,
                        ];
                    }
                }

                if ($href !== null) {
                    $currentHref = $href;
                    $currentAnchor = $child['text'] ?? '';
                } else {
                    $currentHref = null;
                    $currentAnchor = '';
                    static::extractInternalLinksWithAnchorFromNode($child, $links);
                }
            }
        }

        // Flush final link
        if ($currentHref !== null && $currentAnchor !== '') {
            if (preg_match('#^statamic://entry::(.+)$#', $currentHref, $m)) {
                $links[] = [
                    'entry_id' => $m[1],
                    'anchor_text' => $currentAnchor,
                    'href' => $currentHref,
                ];
            }
        }
    }

    protected static function getInternalLinkHref(array $node): ?string
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (($mark['type'] ?? '') === 'link') {
                $href = $mark['attrs']['href'] ?? '';

                if (str_starts_with($href, 'statamic://entry::')) {
                    return $href;
                }
            }
        }

        return null;
    }

    /**
     * Extract external links from a node, merging adjacent text nodes with the same link href.
     */
    protected static function extractExternalLinksFromNode(array $node, array &$links): void
    {
        if (! isset($node['content']) || ! is_array($node['content'])) {
            return;
        }

        $currentHref = null;
        $currentAnchor = '';

        foreach ($node['content'] as $child) {
            $href = static::getExternalLinkHref($child);

            if ($href !== null && $href === $currentHref) {
                $currentAnchor .= $child['text'] ?? '';
            } else {
                if ($currentHref !== null && $currentAnchor !== '') {
                    $links[] = [
                        'url' => $currentHref,
                        'anchor_text' => $currentAnchor,
                    ];
                }

                if ($href !== null) {
                    $currentHref = $href;
                    $currentAnchor = $child['text'] ?? '';
                } else {
                    $currentHref = null;
                    $currentAnchor = '';
                    static::extractExternalLinksFromNode($child, $links);
                }
            }
        }

        if ($currentHref !== null && $currentAnchor !== '') {
            $links[] = [
                'url' => $currentHref,
                'anchor_text' => $currentAnchor,
            ];
        }
    }

    protected static function getExternalLinkHref(array $node): ?string
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (($mark['type'] ?? '') === 'link') {
                $href = $mark['attrs']['href'] ?? '';

                if (preg_match('#^https?://#i', $href) || preg_match('#^www\.#i', $href)) {
                    return $href;
                }
            }
        }

        return null;
    }

    protected static function extractLinksFromNode(array $node, array &$links): void
    {
        // Check marks for links
        if (isset($node['marks']) && is_array($node['marks'])) {
            foreach ($node['marks'] as $mark) {
                if (($mark['type'] ?? '') !== 'link') {
                    continue;
                }

                $href = $mark['attrs']['href'] ?? '';

                // Statamic internal link format: statamic://entry::uuid
                if (preg_match('#^statamic://entry::(.+)$#', $href, $matches)) {
                    $links[] = $matches[1];
                }
            }
        }

        // Recurse into children
        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                static::extractLinksFromNode($child, $links);
            }
        }
    }
}
