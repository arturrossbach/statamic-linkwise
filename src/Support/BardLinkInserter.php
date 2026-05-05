<?php

namespace Arturrossbach\Linkwise\Support;

use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

class BardLinkInserter
{
    /**
     * Insert a link mark into Bard ProseMirror JSON content.
     * Returns the modified content, or null if anchor not found or already linked.
     */
    public static function insertLink(array $bardContent, string $anchorText, string $targetEntryId): ?array
    {
        $href = 'statamic://entry::'.$targetEntryId;

        foreach ($bardContent as $i => $node) {
            $result = static::processNode($node, $anchorText, $href);

            if ($result !== null) {
                $bardContent[$i] = $result;

                return $bardContent;
            }
        }

        return null;
    }

    /**
     * Insert a link with a custom href (for external URLs or entry references).
     */
    public static function insertLinkWithHref(array $bardContent, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
        foreach ($bardContent as $i => $node) {
            $result = static::processNode($node, $anchorText, $href, $caseSensitive);

            if ($result !== null) {
                $bardContent[$i] = $result;

                return $bardContent;
            }
        }

        return null;
    }

    /**
     * Multi-insert variant: wrap EVERY valid unlinked occurrence in the Bard tree.
     * Cross-text-node matches and matches inside text nodes that already carry a
     * link mark are left alone. Returns null when no insertion was made.
     */
    public static function insertAllLinksWithHref(array $bardContent, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
        $modified = false;
        foreach ($bardContent as $i => $node) {
            $result = static::processNodeAll($node, $anchorText, $href, $caseSensitive);
            if ($result !== null) {
                $bardContent[$i] = $result;
                $modified = true;
            }
        }

        return $modified ? $bardContent : null;
    }

    protected static function processNodeAll(array $node, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
        // Don't recurse into nodes whose contents must stay untouched. Code blocks
        // are the obvious one — wrapping inline links inside SQL/JS code corrupts
        // the rendered output and Bard editors with no codeblock extension error.
        // Replicator 'set' nodes have their own walker.
        if (in_array($node['type'] ?? '', ['set', 'codeBlock', 'code_block', 'horizontalRule', 'horizontal_rule', 'image'], true)) {
            return null;
        }

        if (! isset($node['content']) || ! is_array($node['content'])) {
            return null;
        }

        $modified = false;

        // Recurse into nested children first (their own content arrays).
        foreach ($node['content'] as $j => $child) {
            if (isset($child['content']) && is_array($child['content'])) {
                $childResult = static::processNodeAll($child, $anchorText, $href, $caseSensitive);
                if ($childResult !== null) {
                    $node['content'][$j] = $childResult;
                    $modified = true;
                }
            }
        }

        // Then wrap matches in this level's direct text children.
        $result = static::findAndLinkAllInChildren($node['content'], $anchorText, $href, $caseSensitive);
        if ($result !== null) {
            $node['content'] = $result;
            $modified = true;
        }

        return $modified ? $node : null;
    }

    /**
     * For each direct text child, wrap every valid match with a link mark.
     * Skips text nodes that already carry a link mark (preserves existing links).
     * Cross-node matches are NOT supported by this multi-walker.
     */
    protected static function findAndLinkAllInChildren(array $children, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
        $anchorLen = mb_strlen($anchorText);
        if ($anchorLen === 0) {
            return null;
        }

        $linkAttrs = ['href' => $href];
        try {
            if (config('linkwise.open_in_new_tab', false)) {
                $linkAttrs['target'] = '_blank';
            }
        } catch (\Throwable) {
            // ignore — config may not be bound in unit tests
        }
        $linkMark = ['type' => 'link', 'attrs' => $linkAttrs];

        $modified = false;
        $newChildren = [];

        foreach ($children as $child) {
            if (($child['type'] ?? '') !== 'text' || ! isset($child['text'])) {
                $newChildren[] = $child;
                continue;
            }

            // Skip text nodes already carrying any link mark — don't double-wrap.
            $hasLinkMark = false;
            foreach ($child['marks'] ?? [] as $m) {
                if (($m['type'] ?? '') === 'link') {
                    $hasLinkMark = true;
                    break;
                }
            }
            if ($hasLinkMark) {
                $newChildren[] = $child;
                continue;
            }

            $text = $child['text'];
            $matches = [];
            $offset = 0;
            while (true) {
                $pos = $caseSensitive
                    ? mb_strpos($text, $anchorText, $offset)
                    : mb_stripos($text, $anchorText, $offset);
                if ($pos === false) {
                    break;
                }
                if (static::isAtWordBoundary($text, $pos, $anchorLen)) {
                    $matches[] = $pos;
                }
                $offset = $pos + $anchorLen;
            }

            if (empty($matches)) {
                $newChildren[] = $child;
                continue;
            }

            $existingMarks = $child['marks'] ?? [];
            $cursor = 0;
            $textLen = mb_strlen($text);

            foreach ($matches as $matchPos) {
                if ($matchPos > $cursor) {
                    $segment = $child;
                    $segment['text'] = mb_substr($text, $cursor, $matchPos - $cursor);
                    $newChildren[] = $segment;
                }
                $matchNode = $child;
                $matchNode['text'] = mb_substr($text, $matchPos, $anchorLen);
                $matchNode['marks'] = array_merge($existingMarks, [$linkMark]);
                $newChildren[] = $matchNode;
                $cursor = $matchPos + $anchorLen;
            }

            if ($cursor < $textLen) {
                $segment = $child;
                $segment['text'] = mb_substr($text, $cursor);
                $newChildren[] = $segment;
            }

            $modified = true;
        }

        return $modified ? $newChildren : null;
    }

    /**
     * Insert a link into an entry's Bard fields.
     * Finds the first Bard field containing the anchor text and modifies it.
     */
    public static function insertLinkIntoEntry(string $sourceEntryId, string $anchorText, string $targetEntryId): bool
    {
        return static::insertLinkIntoEntryWithHref(
            $sourceEntryId,
            $anchorText,
            'statamic://entry::'.$targetEntryId,
        );
    }

    /**
     * Insert a link with a custom href into an entry's Bard or Markdown fields.
     */
    /**
     * @throws \Arturrossbach\Linkwise\Exceptions\EntryConflictException if entry was modified concurrently
     */
    /**
     * Multi-insert variant of insertLinkIntoEntryWithHref.
     * Wraps EVERY valid unlinked occurrence across all Bard / Markdown /
     * Replicator fields in the entry. Returns the number of insertions made
     * (0 if none, which means no save happened).
     *
     * @throws \Arturrossbach\Linkwise\Exceptions\EntryConflictException if entry was modified concurrently
     */
    public static function insertAllLinksIntoEntryWithHref(string $sourceEntryId, string $anchorText, string $href, bool $caseSensitive = false, bool $save = true): int
    {
        [$entry, $hash] = SafeEntrySaver::load($sourceEntryId);

        if (! $entry) {
            return 0;
        }

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return 0;
        }

        $totalInserted = 0;
        $touched = false;

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                $before = static::countLinksTo($value, $href);
                $modified = static::insertAllLinksWithHref($value, $anchorText, $href, $caseSensitive);
                if ($modified !== null) {
                    $after = static::countLinksTo($modified, $href);
                    $totalInserted += max(0, $after - $before);
                    $entry->set($handle, $modified);
                    $touched = true;
                }
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $before = static::countLinksToInReplicator($value, $href);
                $modified = static::processAllInReplicator($value, $anchorText, $href, $caseSensitive);
                if ($modified !== null) {
                    $after = static::countLinksToInReplicator($modified, $href);
                    $totalInserted += max(0, $after - $before);
                    $entry->set($handle, $modified);
                    $touched = true;
                }
            } elseif ($field->type() === 'markdown' && is_string($value) && ! empty($value)) {
                $before = substr_count($value, '('.$href.')');
                $modified = static::insertAllLinksIntoMarkdown($value, $anchorText, $href, $caseSensitive);
                if ($modified !== null) {
                    $after = substr_count($modified, '('.$href.')');
                    $totalInserted += max(0, $after - $before);
                    $entry->set($handle, $modified);
                    $touched = true;
                }
            }
        }

        if ($touched && $save) {
            SafeEntrySaver::save($entry, $hash);
        }

        return $totalInserted;
    }

    /** Count link marks pointing at $href in a Bard subtree. */
    protected static function countLinksTo(array $bardContent, string $href): int
    {
        $count = 0;
        foreach ($bardContent as $node) {
            if (isset($node['marks'])) {
                foreach ($node['marks'] as $m) {
                    if (($m['type'] ?? '') === 'link' && ($m['attrs']['href'] ?? '') === $href) {
                        $count++;
                    }
                }
            }
            if (isset($node['content']) && is_array($node['content'])) {
                $count += static::countLinksTo($node['content'], $href);
            }
        }

        return $count;
    }

    /** Count link marks pointing at $href anywhere in a replicator structure. */
    protected static function countLinksToInReplicator(array $sets, string $href): int
    {
        $count = 0;
        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $count += static::countLinksTo($value, $href);
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $count += static::countLinksToInReplicator($value, $href);
                }
            }
        }

        return $count;
    }

    /** Multi-insert across nested Bard fragments inside a replicator. */
    protected static function processAllInReplicator(array $sets, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
        $modified = false;
        foreach ($sets as $i => $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $result = static::insertAllLinksWithHref($value, $anchorText, $href, $caseSensitive);
                    if ($result !== null) {
                        $sets[$i][$key] = $result;
                        $modified = true;
                    }
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $result = static::processAllInReplicator($value, $anchorText, $href, $caseSensitive);
                    if ($result !== null) {
                        $sets[$i][$key] = $result;
                        $modified = true;
                    }
                }
            }
        }

        return $modified ? $sets : null;
    }

    public static function insertLinkIntoEntryWithHref(string $sourceEntryId, string $anchorText, string $href, bool $caseSensitive = false, bool $save = true): bool
    {
        [$entry, $hash] = SafeEntrySaver::load($sourceEntryId);

        if (! $entry) {
            return false;
        }

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return false;
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                $modified = static::insertLinkWithHref($value, $anchorText, $href, $caseSensitive);

                if ($modified !== null) {
                    $entry->set($handle, $modified);
                    if ($save) {
                        SafeEntrySaver::save($entry, $hash);
                    }

                    return true;
                }
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $modified = static::processReplicatorWithHref($value, $anchorText, $href, $caseSensitive);

                if ($modified !== null) {
                    $entry->set($handle, $modified);
                    if ($save) {
                        SafeEntrySaver::save($entry, $hash);
                    }

                    return true;
                }
            } elseif (in_array($field->type(), ['markdown', 'textarea', 'text'], true)
                && is_string($value) && ! empty($value) && $handle !== 'title'
                && ($field->type() === 'markdown' || InsertableContentFilter::isContent($value, $handle))) {
                // textarea/text fields at top-level are handled the same way
                // as markdown — insertLinkIntoMarkdown wraps the anchor with
                // [text](href) syntax. Rendering is the user's template
                // responsibility; Linkwise's job is to write the syntax
                // wherever a linkable opportunity sits, regardless of how
                // the field will eventually be rendered. Skip 'title' since
                // we never link the entry's own title field. For text/textarea
                // (but NOT markdown — that field type is full content by
                // contract) the InsertableContentFilter additionally rejects
                // top-level URL/asset-handle fields like `link` or `image_url`
                // so we don't wrap a raw URL value in markdown link syntax.
                $modified = static::insertLinkIntoMarkdown($value, $anchorText, $href, $caseSensitive);

                if ($modified !== null) {
                    $entry->set($handle, $modified);
                    if ($save) {
                        SafeEntrySaver::save($entry, $hash);
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Insert a link into Markdown content by replacing the first occurrence of anchor text.
     */
    /**
     * Check if a match position is at word boundaries (not inside another word).
     */
    protected static function isAtWordBoundary(string $text, int $pos, int $length): bool
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

            if (! static::isAtWordBoundary($markdown, $pos, $anchorLen)) {
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

    public static function insertLinkIntoMarkdown(string $markdown, string $anchorText, string $href, bool $caseSensitive = false): ?string
    {
        // Check if anchor text is already linked in Markdown
        $escapedAnchor = preg_quote($anchorText, '/');
        $pattern = '/\['.$escapedAnchor.'\]\(/'.($caseSensitive ? '' : 'i');
        if (preg_match($pattern, $markdown)) {
            return null; // Already linked
        }

        $anchorLen = mb_strlen($anchorText);
        $offset = 0;

        // Walk through all occurrences. Return on the first one that sits at a word boundary
        // (so "database" skips "databases" and hits the standalone "Database" next).
        while (true) {
            $pos = $caseSensitive
                ? mb_strpos($markdown, $anchorText, $offset)
                : mb_stripos($markdown, $anchorText, $offset);

            if ($pos === false) {
                return null;
            }

            if (static::isAtWordBoundary($markdown, $pos, $anchorLen)) {
                $actualText = mb_substr($markdown, $pos, $anchorLen);
                $before = mb_substr($markdown, 0, $pos);
                $after = mb_substr($markdown, $pos + $anchorLen);

                return $before.'['.$actualText.']('.$href.')'.$after;
            }

            $offset = $pos + $anchorLen;
        }
    }

    /**
     * Process a Replicator field value with custom href.
     */
    protected static function processReplicatorWithHref(array $sets, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
        foreach ($sets as $i => $set) {
            if (! is_array($set)) {
                continue;
            }

            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                    continue;
                }

                // Plain-string field nested in a replicator (Peak Cards
                // heading/text, accordion bodies, button labels, etc.).
                // Treat the same way as a top-level markdown field: wrap
                // the anchor with [text](href). Filter out non-content
                // strings (UUIDs from entry/asset references, numeric and
                // boolean-like values, anything too short to be content)
                // so we never try to insert a link into a UUID or a
                // config-enum string. Quality filters mirror those used
                // on the read side in EntryFieldWalker.
                if (is_string($value)) {
                    if (! InsertableContentFilter::isContent($value, (string) $key)) {
                        continue;
                    }
                    $modified = static::insertLinkIntoMarkdown($value, $anchorText, $href, $caseSensitive);
                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                    continue;
                }

                if (! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $modified = static::insertLinkWithHref($value, $anchorText, $href, $caseSensitive);

                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $modified = static::processReplicatorWithHref($value, $anchorText, $href, $caseSensitive);

                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Process a Replicator field value to find and modify nested Bard content.
     */
    protected static function processReplicator(array $sets, string $anchorText, string $targetEntryId): ?array
    {
        $href = 'statamic://entry::'.$targetEntryId;

        foreach ($sets as $i => $set) {
            if (! is_array($set)) {
                continue;
            }

            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                    continue;
                }

                // Plain-string field — same coverage as the WithHref path
                // so legacy callers (insertLinkIntoEntry → insertLink path)
                // also reach card text and other non-Bard nested content.
                if (is_string($value)) {
                    if (! InsertableContentFilter::isContent($value, (string) $key)) {
                        continue;
                    }
                    $modified = static::insertLinkIntoMarkdown($value, $anchorText, $href);
                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                    continue;
                }

                if (! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $modified = static::insertLink($value, $anchorText, $targetEntryId);

                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $modified = static::processReplicator($value, $anchorText, $targetEntryId);

                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Process a single ProseMirror node, looking for anchor text in its children.
     * Returns the modified node, or null if not found.
     */
    protected static function processNode(array $node, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
        // Don't recurse into nodes whose contents must stay untouched. Code blocks
        // are the obvious one — wrapping inline links inside SQL/JS code corrupts
        // the rendered output. Replicator 'set' nodes have their own walker.
        if (in_array($node['type'] ?? '', ['set', 'codeBlock', 'code_block', 'horizontalRule', 'horizontal_rule', 'image'], true)) {
            return null;
        }

        // Process nodes with content (paragraph, heading, etc.)
        if (isset($node['content']) && is_array($node['content'])) {
            $result = static::findAndLinkInChildren($node['content'], $anchorText, $href, $caseSensitive);

            if ($result !== null) {
                $node['content'] = $result;

                return $node;
            }

            // Recurse into child nodes that may have their own content
            foreach ($node['content'] as $j => $child) {
                $result = static::processNode($child, $anchorText, $href, $caseSensitive);

                if ($result !== null) {
                    $node['content'][$j] = $result;

                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * Search for anchor text across child text nodes and insert a link mark.
     * Handles text spanning across multiple text nodes and node splitting.
     */
    protected static function findAndLinkInChildren(array $children, string $anchorText, string $href, bool $caseSensitive = false): ?array
    {
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

        $anchorLen = mb_strlen($anchorText);
        $offset = 0;
        $pos = null;

        // Walk all occurrences — accept the first that sits at a word boundary, doesn't
        // cross a non-text-node marker, and isn't already linked to our href.
        // "database" must skip "databases" and hit the standalone "Database" next.
        while (true) {
            $found = $caseSensitive
                ? mb_strpos($fullText, $anchorText, $offset)
                : mb_stripos($fullText, $anchorText, $offset);

            if ($found === false) {
                return null;
            }

            $valid = static::isAtWordBoundary($fullText, $found, $anchorLen);

            if ($valid && str_contains(mb_substr($fullText, $found, $anchorLen), "\0")) {
                $valid = false;
            }

            if ($valid) {
                $startMap = $nodeMap[$found];
                if ($startMap['index'] === -1) {
                    $valid = false;
                } else {
                    $startChild = $children[$startMap['index']];
                    if (static::isLinkedToHref($startChild, $href)) {
                        return null; // Already linked to this target at this occurrence — nothing to do
                    }
                }
            }

            if ($valid) {
                $pos = $found;
                break;
            }

            $offset = $found + $anchorLen;
        }

        // Determine which child nodes are affected
        $startNodeIndex = $nodeMap[$pos]['index'];
        $endNodeIndex = $nodeMap[$pos + $anchorLen - 1]['index'];
        $startOffset = $nodeMap[$pos]['offset'];
        $endOffset = $nodeMap[$pos + $anchorLen - 1]['offset'] + 1;

        $attrs = ['href' => $href];

        // Apply open_in_new_tab setting
        try {
            if (config('linkwise.open_in_new_tab', false)) {
                $attrs['target'] = '_blank';
            }
        } catch (\Throwable) {
            // Config not available (unit tests)
        }

        $linkMark = ['type' => 'link', 'attrs' => $attrs];

        if ($startNodeIndex === $endNodeIndex) {
            // Anchor is within a single text node — split it
            return static::splitSingleNode($children, $startNodeIndex, $startOffset, $anchorLen, $linkMark);
        }

        // Anchor spans multiple text nodes — add link mark to each
        return static::linkAcrossNodes($children, $startNodeIndex, $startOffset, $endNodeIndex, $endOffset, $linkMark);
    }

    /**
     * Split a single text node to insert a link mark on the anchor portion.
     */
    protected static function splitSingleNode(array $children, int $nodeIndex, int $offset, int $anchorLen, array $linkMark): array
    {
        $node = $children[$nodeIndex];
        $text = $node['text'];
        $existingMarks = $node['marks'] ?? [];

        $prefix = mb_substr($text, 0, $offset);
        $anchor = mb_substr($text, $offset, $anchorLen);
        $suffix = mb_substr($text, $offset + $anchorLen);

        $replacement = [];

        if ($prefix !== '') {
            $prefixNode = ['type' => 'text', 'text' => $prefix];

            if (! empty($existingMarks)) {
                $prefixNode['marks'] = $existingMarks;
            }

            $replacement[] = $prefixNode;
        }

        $anchorNode = [
            'type' => 'text',
            'text' => $anchor,
            'marks' => array_merge(static::stripLinkMarks($existingMarks), [$linkMark]),
        ];
        $replacement[] = $anchorNode;

        if ($suffix !== '') {
            $suffixNode = ['type' => 'text', 'text' => $suffix];

            if (! empty($existingMarks)) {
                $suffixNode['marks'] = $existingMarks;
            }

            $replacement[] = $suffixNode;
        }

        // Replace the original node with the split nodes
        array_splice($children, $nodeIndex, 1, $replacement);

        return $children;
    }

    /**
     * Add link marks across multiple consecutive text nodes.
     */
    protected static function linkAcrossNodes(array $children, int $startIndex, int $startOffset, int $endIndex, int $endOffset, array $linkMark): array
    {
        // Process from end to start to preserve indices
        $newChildren = [];

        foreach ($children as $i => $child) {
            if ($i < $startIndex || $i > $endIndex) {
                $newChildren[] = $child;

                continue;
            }

            if (($child['type'] ?? '') !== 'text') {
                $newChildren[] = $child;

                continue;
            }

            $text = $child['text'];
            $existingMarks = $child['marks'] ?? [];

            if ($i === $startIndex && $startOffset > 0) {
                // Split: prefix (no link) + remainder (with link)
                $prefix = mb_substr($text, 0, $startOffset);
                $linked = mb_substr($text, $startOffset);

                $prefixNode = ['type' => 'text', 'text' => $prefix];

                if (! empty($existingMarks)) {
                    $prefixNode['marks'] = $existingMarks;
                }

                $newChildren[] = $prefixNode;
                $newChildren[] = [
                    'type' => 'text',
                    'text' => $linked,
                    'marks' => array_merge(static::stripLinkMarks($existingMarks), [$linkMark]),
                ];
            } elseif ($i === $endIndex && $endOffset < mb_strlen($text)) {
                // Split: linked portion + suffix (no link)
                $linked = mb_substr($text, 0, $endOffset);
                $suffix = mb_substr($text, $endOffset);

                $newChildren[] = [
                    'type' => 'text',
                    'text' => $linked,
                    'marks' => array_merge(static::stripLinkMarks($existingMarks), [$linkMark]),
                ];

                $suffixNode = ['type' => 'text', 'text' => $suffix];

                if (! empty($existingMarks)) {
                    $suffixNode['marks'] = $existingMarks;
                }

                $newChildren[] = $suffixNode;
            } else {
                // Entire node gets the link mark
                $newChildren[] = [
                    'type' => 'text',
                    'text' => $text,
                    'marks' => array_merge(static::stripLinkMarks($existingMarks), [$linkMark]),
                ];
            }
        }

        return $newChildren;
    }

    /**
     * Check if a node is already linked to the exact same href.
     */
    protected static function isLinkedToHref(array $node, string $href): bool
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (($mark['type'] ?? '') === 'link' && ($mark['attrs']['href'] ?? '') === $href) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all link marks from a marks array (to prevent duplicates).
     */
    protected static function stripLinkMarks(array $marks): array
    {
        return array_values(array_filter($marks, fn ($m) => ($m['type'] ?? '') !== 'link'));
    }

    protected static function looksLikeReplicatorContent(array $value): bool
    {
        $first = reset($value);

        return is_array($first) && isset($first['type']) && isset($first['id']);
    }
}
