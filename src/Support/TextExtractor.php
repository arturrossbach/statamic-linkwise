<?php

namespace Arturrossbach\Linkwise\Support;

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
     * Like {@see linksFromBard} but returns EVERY occurrence — one entry-id
     * per text-node link mark — instead of deduplicating per target. Used by
     * EntryIndexer for `outboundLinkOccurrences` so LinkReport's inbound
     * count matches the modal's per-text-node listing (Bug 2026-05-12).
     *
     * @return list<string>
     */
    public static function linksFromBardWithOccurrences(array $bardContent): array
    {
        $links = [];

        foreach ($bardContent as $node) {
            static::extractLinksFromNode($node, $links);
        }

        return array_values($links);
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
     * Like {@see linksFromMarkdown} but with every match as a separate entry
     * (no dedup). Mirrors {@see linksFromBardWithOccurrences} for outbound-
     * link occurrence counts (Bug 2026-05-12).
     *
     * @return list<string>
     */
    public static function linksFromMarkdownWithOccurrences(string $markdown): array
    {
        if (preg_match_all('#\[[^\[\]]*\]\(statamic://entry::([^)]+)\)#', $markdown, $matches)) {
            return array_values($matches[1]);
        }

        return [];
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

    /**
     * Single-pass walk that returns the flat text plus internal + external
     * links, each annotated with the character offset where its anchor sits
     * in the returned text. Solves the "context shown at wrong position"
     * class of bugs (2026-05-11): callers that need to render a snippet
     * around an EXISTING link can pass the offset directly to
     * {@see ContextExtractor::extractAtOffset()} instead of relying on a
     * naive occurrence counter, which silently picks unlinked matches when
     * the same anchor word appears both linked and unlinked in the entry.
     *
     * Text-accumulation contract mirrors {@see fromBard()} exactly (top-
     * level newline join, set/codeBlock skip, block-vs-inline separators,
     * adjacent same-href merge) — tested in lockstep so the two walkers
     * cannot drift.
     *
     * @return array{
     *     text: string,
     *     internal_links: array<array{entry_id: string, anchor_text: string, href: string, offset: int}>,
     *     external_links: array<array{url: string, anchor_text: string, offset: int}>,
     * }
     */
    public static function extractTextAndLinksFromBard(array $bardContent): array
    {
        $text = '';
        $internal = [];
        $external = [];

        foreach ($bardContent as $node) {
            $nodeState = static::collectFromNode($node);
            if ($nodeState['text'] === '') {
                continue; // matches fromBard's "skip empty top-level node"
            }
            $shift = mb_strlen($text);
            if ($text !== '') {
                $text .= "\n";
                $shift += 1;
            }
            $text .= $nodeState['text'];
            foreach ($nodeState['internal_links'] as $link) {
                $link['offset'] += $shift;
                $internal[] = $link;
            }
            foreach ($nodeState['external_links'] as $link) {
                $link['offset'] += $shift;
                $external[] = $link;
            }
        }

        return [
            'text' => $text,
            'internal_links' => $internal,
            'external_links' => $external,
        ];
    }

    /**
     * Walk a single Bard node returning its flat text + links with offsets
     * relative to that returned text. Recursive — used by
     * {@see extractTextAndLinksFromBard()} and by the set-walker for nested
     * Bard subtrees.
     *
     * @return array{
     *     text: string,
     *     internal_links: array<array{entry_id: string, anchor_text: string, href: string, offset: int}>,
     *     external_links: array<array{url: string, anchor_text: string, offset: int}>,
     * }
     */
    protected static function collectFromNode(array $node): array
    {
        $type = $node['type'] ?? '';

        // Mirror fromBard: skip code blocks entirely.
        if (in_array($type, ['codeBlock', 'code_block'], true)) {
            return ['text' => '', 'internal_links' => [], 'external_links' => []];
        }

        // Bard SET — mirror extractTextFromNode set branch: walk attrs.values,
        // strings pass through InsertableContentFilter, nested Bard subtrees
        // recurse via this same collector. Links inside nested Bard are
        // collected with their offsets shifted to where the subtree sits in
        // the set's joined output.
        if ($type === 'set') {
            return static::collectFromSet($node);
        }

        // Text node — emit its text and any link marks. Anchor offset is the
        // position where this text node's content STARTS in the returned text.
        if (isset($node['text'])) {
            $text = $node['text'];
            $state = ['text' => $text, 'internal_links' => [], 'external_links' => []];

            $internalHref = static::getInternalLinkHref($node);
            if ($internalHref !== null && preg_match('#^statamic://entry::(.+)$#', $internalHref, $m)) {
                $state['internal_links'][] = [
                    'entry_id' => $m[1],
                    'anchor_text' => $text,
                    'href' => $internalHref,
                    'offset' => 0,
                ];
            }

            $externalHref = static::getExternalLinkHref($node);
            if ($externalHref !== null) {
                $state['external_links'][] = [
                    'url' => $externalHref,
                    'anchor_text' => $text,
                    'offset' => 0,
                ];
            }

            return $state;
        }

        // Container node — recurse into children. Block-level containers
        // separate children with a single space (matches extractTextFromNode).
        if (isset($node['content']) && is_array($node['content'])) {
            $blockTypes = ['table', 'tableRow', 'tableCell', 'tableHeader', 'bulletList', 'orderedList', 'listItem', 'blockquote'];
            $separator = in_array($type, $blockTypes, true) ? ' ' : '';

            return static::collectFromChildren($node['content'], $separator);
        }

        return ['text' => '', 'internal_links' => [], 'external_links' => []];
    }

    /**
     * Combine child node results into the parent's output, applying $separator
     * between non-empty children. Adjacent text nodes with the SAME link href
     * are merged into one link record (matches legacy
     * {@see extractInternalLinksWithAnchorFromNode()} behavior).
     *
     * @return array{text: string, internal_links: array, external_links: array}
     */
    protected static function collectFromChildren(array $children, string $separator): array
    {
        $text = '';
        $internal = [];
        $external = [];

        foreach ($children as $child) {
            $childState = static::collectFromNode($child);
            if ($childState['text'] === '' && empty($childState['internal_links']) && empty($childState['external_links'])) {
                continue;
            }
            $shift = mb_strlen($text);
            if ($text !== '' && $separator !== '') {
                $text .= $separator;
                $shift += mb_strlen($separator);
            }
            $text .= $childState['text'];
            foreach ($childState['internal_links'] as $link) {
                $link['offset'] += $shift;
                $internal[] = static::mergeOrAppendInternal($internal, $link);
            }
            foreach ($childState['external_links'] as $link) {
                $link['offset'] += $shift;
                $external[] = static::mergeOrAppendExternal($external, $link);
            }
        }

        // mergeOrAppend returns null when it has folded the new link into the
        // last entry in-place — strip those.
        return [
            'text' => $text,
            'internal_links' => array_values(array_filter($internal, fn ($l) => $l !== null)),
            'external_links' => array_values(array_filter($external, fn ($l) => $l !== null)),
        ];
    }

    /**
     * If the new link is adjacent (no gap in text) to the previous link with
     * the same href, fold its anchor_text into the previous record and return
     * null. Otherwise return the new link unchanged.
     *
     * Adjacency check: previous_offset + length(previous_anchor) === new_offset.
     * Matches the legacy walker's "merge adjacent text nodes with same link"
     * behavior, but works on offsets directly instead of carrying pending state.
     */
    protected static function mergeOrAppendInternal(array &$existing, array $newLink): ?array
    {
        $count = count($existing);
        if ($count === 0) {
            return $newLink;
        }
        $prev = $existing[$count - 1];
        if ($prev === null) {
            return $newLink;
        }
        if ($prev['href'] === $newLink['href']
            && $prev['offset'] + mb_strlen($prev['anchor_text']) === $newLink['offset']) {
            $existing[$count - 1]['anchor_text'] .= $newLink['anchor_text'];

            return null;
        }

        return $newLink;
    }

    protected static function mergeOrAppendExternal(array &$existing, array $newLink): ?array
    {
        $count = count($existing);
        if ($count === 0) {
            return $newLink;
        }
        $prev = $existing[$count - 1];
        if ($prev === null) {
            return $newLink;
        }
        if ($prev['url'] === $newLink['url']
            && $prev['offset'] + mb_strlen($prev['anchor_text']) === $newLink['offset']) {
            $existing[$count - 1]['anchor_text'] .= $newLink['anchor_text'];

            return null;
        }

        return $newLink;
    }

    /**
     * Walk a Bard set's attrs.values — strings via InsertableContentFilter,
     * nested Bard trees recursed into. Mirrors extractTextFromNode's set
     * branch which joins parts with a single space.
     */
    /**
     * Markdown counterpart to {@see extractTextAndLinksFromBard()}: returns
     * the plain text (Markdown formatting characters stripped, link syntax
     * replaced with anchor) and link records annotated with the offset where
     * each anchor sits in the returned text.
     *
     * The plain-text stripping mirrors {@see \Arturrossbach\Linkwise\Indexer\EntryIndexer::indexEntry()}'s
     * Markdown handling (strip `[…](url)` to anchor, then remove `# * _ ~ ` >`)
     * so callers can build per-entry text+offset bundles that line up with
     * the indexed text shape.
     *
     * @return array{
     *     text: string,
     *     internal_links: array<array{entry_id: string, anchor_text: string, href: string, offset: int}>,
     *     external_links: array<array{url: string, anchor_text: string, offset: int}>,
     * }
     */
    public static function extractTextAndLinksFromMarkdown(string $markdown): array
    {
        $internal = [];
        $external = [];
        $output = '';

        if (preg_match_all('/\[([^\[\]]*)\]\(([^)]+)\)/', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            $lastPos = 0;
            foreach ($matches[0] as $i => $full) {
                $matchStart = $full[1];
                // Append + strip Markdown formatting chars from the gap text.
                $output .= preg_replace('/[#*_~`>]/', '', substr($markdown, $lastPos, $matchStart - $lastPos));

                $anchor = $matches[1][$i][0];
                $url = $matches[2][$i][0];
                $linkOffset = mb_strlen($output);
                $output .= $anchor;

                if (preg_match('#^statamic://entry::(.+)$#', $url, $em)) {
                    $internal[] = [
                        'entry_id' => $em[1],
                        'anchor_text' => $anchor,
                        'href' => $url,
                        'offset' => $linkOffset,
                    ];
                } elseif (preg_match('#^https?://#i', $url) || stripos($url, 'www.') === 0) {
                    $external[] = [
                        'url' => $url,
                        'anchor_text' => $anchor,
                        'offset' => $linkOffset,
                    ];
                }

                $lastPos = $matchStart + strlen($full[0]);
            }
            $output .= preg_replace('/[#*_~`>]/', '', substr($markdown, $lastPos));
        } else {
            $output = preg_replace('/[#*_~`>]/', '', $markdown);
        }

        return ['text' => $output, 'internal_links' => $internal, 'external_links' => $external];
    }

    /**
     * Walk every content-bearing field of a Statamic entry (Bard, Replicator-
     * nested Bard, Markdown) and return one flat text + offset-annotated link
     * lists. The replacement for the "build $entryText separately, then
     * iterate links with a naive occurrence counter" pattern in
     * DashboardController/UrlReplacer/DomainReport that silently picked
     * unlinked positions when an anchor appeared both linked and unlinked
     * in the same entry (Bug 2026-05-11).
     *
     * Field order + "\n" inter-field separator mirror EntryIndexer's text
     * accumulation; offsets are global, valid for direct use with
     * {@see ContextExtractor::extractAtOffset()}.
     *
     * @return array{
     *     text: string,
     *     internal_links: array<array{entry_id: string, anchor_text: string, href: string, offset: int}>,
     *     external_links: array<array{url: string, anchor_text: string, offset: int}>,
     * }
     */
    public static function extractFromEntry($entry): array
    {
        $state = ['text' => '', 'internal_links' => [], 'external_links' => [], 'has_output' => false];

        $append = function (array $sub) use (&$state): void {
            if ($sub['text'] === '' && empty($sub['internal_links']) && empty($sub['external_links'])) {
                return;
            }
            $shift = mb_strlen($state['text']);
            if ($state['has_output']) {
                $state['text'] .= "\n";
                $shift += 1;
            }
            $state['text'] .= $sub['text'];
            foreach ($sub['internal_links'] as $l) {
                $l['offset'] += $shift;
                $state['internal_links'][] = $l;
            }
            foreach ($sub['external_links'] as $l) {
                $l['offset'] += $shift;
                $state['external_links'][] = $l;
            }
            $state['has_output'] = true;
        };

        EntryFieldWalker::walk(
            $entry,
            function (array $bard) use ($append): void {
                $append(static::extractTextAndLinksFromBard($bard));
            },
            function (string $md) use ($append): void {
                $append(static::extractTextAndLinksFromMarkdown($md));
            },
        );

        unset($state['has_output']);

        return $state;
    }

    protected static function collectFromSet(array $node): array
    {
        $values = $node['attrs']['values'] ?? null;
        if (! is_array($values)) {
            return ['text' => '', 'internal_links' => [], 'external_links' => []];
        }

        $text = '';
        $internal = [];
        $external = [];

        foreach ($values as $key => $val) {
            if ($key === 'type' || $key === 'enabled' || $key === 'id') {
                continue;
            }

            $partText = '';
            $partInternal = [];
            $partExternal = [];

            if (is_string($val)) {
                if (! InsertableContentFilter::isContent($val, (string) $key)) {
                    continue;
                }
                $partText = trim($val);
                if ($partText === '') {
                    continue;
                }
            } elseif (is_array($val) && ! empty($val) && isset($val[0]['type'])) {
                $sub = static::extractTextAndLinksFromBard($val);
                if ($sub['text'] === '') {
                    continue;
                }
                $partText = $sub['text'];
                $partInternal = $sub['internal_links'];
                $partExternal = $sub['external_links'];
            } else {
                continue;
            }

            $shift = mb_strlen($text);
            if ($text !== '') {
                $text .= ' ';
                $shift += 1;
            }
            $text .= $partText;
            foreach ($partInternal as $link) {
                $link['offset'] += $shift;
                $internal[] = $link;
            }
            foreach ($partExternal as $link) {
                $link['offset'] += $shift;
                $external[] = $link;
            }
        }

        return ['text' => $text, 'internal_links' => $internal, 'external_links' => $external];
    }

    protected static function extractTextFromNode(array $node): string
    {
        $type = $node['type'] ?? '';

        // Code blocks contain syntax (e.g. `<script setup>`, `import { ref }`)
        // that's NOT prose. Including their text in the indexed corpus means:
        //   - keywords get polluted by language tokens (script, setup, import)
        //   - anchor-text candidates can include code fragments that
        //     BardLinkInserter then can't find as prose, surfacing as
        //     "0 of 1 succeeded" failures
        // BardLinkInserter already refuses to recurse into codeBlock on the
        // write side; mirror that on the read side here.
        if (in_array($type, ['codeBlock', 'code_block'], true)) {
            return '';
        }

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
