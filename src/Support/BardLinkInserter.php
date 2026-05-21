<?php

namespace Arturrossbach\Linkwise\Support;

use Arturrossbach\Linkwise\Support\Bard\AnchorPositionFinder;
use Arturrossbach\Linkwise\Support\Markdown\MarkdownLinkInserter;
use Arturrossbach\Linkwise\Support\Replicator\ReplicatorLinkRouter;
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
     *
     * @param  string|null  $expectedSentenceContext  When set, the anchor MUST
     *   sit inside a text region whose surrounding text contains the supplied
     *   sentence context. This is the visual-truth guard: scan captured the
     *   anchor inside sentence X; if the user later prepended a SECOND
     *   occurrence of the anchor at the start of the entry, the naive
     *   "wrap first match" behaviour would silently wrap the new one. With
     *   the guard, the wrap only happens at the position whose surrounding
     *   text matches the captured context. Mismatch → return null.
     */
    public static function insertLinkWithHref(array $bardContent, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?array
    {
        foreach ($bardContent as $i => $node) {
            $result = static::processNode($node, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

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
                if (AnchorPositionFinder::isAtWordBoundary($text, $pos, $anchorLen)) {
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
                $before = ReplicatorLinkRouter::countLinksToInReplicator($value, $href);
                $modified = ReplicatorLinkRouter::processAllInReplicator($value, $anchorText, $href, $caseSensitive);
                if ($modified !== null) {
                    $after = ReplicatorLinkRouter::countLinksToInReplicator($modified, $href);
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

    /**
     * Count link marks pointing at $href in a Bard subtree.
     *
     * Public so {@see ReplicatorLinkRouter::countLinksToInReplicator} can
     * call back into the Bard primitive after navigating through the
     * Replicator structure — Replicator → Bard is the one-way routing
     * direction of the REV-OB-03 split.
     */
    public static function countLinksTo(array $bardContent, string $href): int
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

    public static function insertLinkIntoEntryWithHref(string $sourceEntryId, string $anchorText, string $href, bool $caseSensitive = false, bool $save = true, ?string $expectedSentenceContext = null): bool
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
                $modified = static::insertLinkWithHref($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

                if ($modified !== null) {
                    $entry->set($handle, $modified);
                    if ($save) {
                        SafeEntrySaver::save($entry, $hash);
                    }

                    return true;
                }
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $modified = static::processReplicatorWithHref($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

                if ($modified !== null) {
                    $entry->set($handle, $modified);
                    if ($save) {
                        SafeEntrySaver::save($entry, $hash);
                    }

                    return true;
                }
            } elseif ($field->type() === 'markdown' && is_string($value) && ! empty($value) && $handle !== 'title') {
                // Only `markdown` fields receive markdown-link syntax. `text`
                // and `textarea` are plaintext per Statamic's contract — writing
                // `[anchor](url)` into them would surface as visible literal
                // syntax in any template that doesn't manually pipe through
                // `| markdown`. A future opt-in (`linkwise: true` in the
                // blueprint) can re-enable per-field coverage for users who
                // know their template renders the field as markdown.
                $modified = static::insertLinkIntoMarkdown($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

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
     * Dry-run analog of {@see insertLinkIntoEntryWithHref}: report whether
     * an insert would succeed against the entry as it stands right now,
     * WITHOUT mutating or saving anything.
     *
     * Walks bard / replicator / markdown fields in the SAME order the
     * mutating version does — so a bard field that would succeed wins
     * over a markdown field that would fail (mirroring real behaviour).
     *
     * Return shape — same as {@see findValidMatchPosition}:
     *   - `['ok' => true]` on first field that would accept the wrap
     *   - `['ok' => false, 'reason' => 'anchor_not_found' | 'context_mismatch'
     *      | 'crosses_existing_link' | 'already_linked_to_target',
     *      'blocking_href' => string]` — most-informative failure across
     *      all fields. `blocking_href` is set for the cross-/already-
     *      linked reasons.
     *
     * Used by RelinkService to validate the new link's insertion
     * AFTER the original mark has been removed from the in-memory
     * tree. Atomic command: validation reads real post-removal state,
     * no simulation needed.
     *
     * @return array{ok:bool, reason?:string, blocking_href?:string}
     */
    public static function canInsertLinkIntoEntry(
        string $sourceEntryId,
        string $anchorText,
        string $href,
        bool $caseSensitive = false,
        ?string $expectedSentenceContext = null,
    ): array {
        [$entry] = SafeEntrySaver::load($sourceEntryId);

        if (! $entry) {
            return ['ok' => false, 'reason' => 'anchor_not_found'];
        }

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'anchor_not_found'];
        }

        $bestFailure = null;

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                $result = static::canInsertLinkIntoBardContent($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $result = static::canInsertLinkIntoReplicator($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
            } elseif ($field->type() === 'markdown' && is_string($value) && ! empty($value) && $handle !== 'title') {
                // Markdown dry-run: re-use the existing walker since
                // markdown returns a new string (pass-by-value, no
                // in-place mutation). Result coarser than bard — we get
                // "would succeed / would fail" only, not the specific
                // reason. Bug 17 repro is bard-only; markdown granularity
                // is YAGNI until a markdown-variant bug surfaces.
                $modified = static::insertLinkIntoMarkdown($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
                $result = $modified !== null
                    ? ['ok' => true]
                    : ['ok' => false, 'reason' => 'anchor_not_found'];
            } else {
                continue;
            }

            if ($result['ok'] ?? false) {
                return ['ok' => true];
            }

            $bestFailure = AnchorPositionFinder::pickWorseFailure($bestFailure, $result);
        }

        return $bestFailure ?? ['ok' => false, 'reason' => 'anchor_not_found'];
    }

    /**
     * Bard-tree dry-run mirroring {@see insertLinkWithHref} +
     * {@see processNode}. First node that finds a valid match wins;
     * otherwise return the most-informative failure across all nodes.
     *
     * @return array{ok:bool, reason?:string, blocking_href?:string}
     */
    public static function canInsertLinkIntoBardContent(array $bardContent, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): array
    {
        $bestFailure = null;
        foreach ($bardContent as $node) {
            $result = static::analyzeBardNode($node, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
            if ($result['ok'] ?? false) {
                return $result;
            }
            $bestFailure = AnchorPositionFinder::pickWorseFailure($bestFailure, $result);
        }

        return $bestFailure ?? ['ok' => false, 'reason' => 'anchor_not_found'];
    }

    /**
     * Single-node dry-run mirroring {@see processNode}: first tries direct
     * children (where the link mark would actually land), then recurses
     * into nested child nodes. Same skip-list as the mutating walker so
     * the two paths can't diverge on which nodes count.
     *
     * @return array{ok:bool, reason?:string, blocking_href?:string}
     */
    protected static function analyzeBardNode(array $node, string $anchorText, string $href, bool $caseSensitive, ?string $expectedSentenceContext): array
    {
        // Mirror the processNode skip-list — code blocks, hrs, images,
        // replicator 'set' nodes. Stay in sync if the list ever changes.
        if (in_array($node['type'] ?? '', ['set', 'codeBlock', 'code_block', 'horizontalRule', 'horizontal_rule', 'image'], true)) {
            return ['ok' => false, 'reason' => 'anchor_not_found'];
        }

        if (! isset($node['content']) || ! is_array($node['content'])) {
            return ['ok' => false, 'reason' => 'anchor_not_found'];
        }

        // First: this node's direct children (= the actual wrap target).
        $direct = AnchorPositionFinder::find($node['content'], $anchorText, $href, $caseSensitive, $expectedSentenceContext);
        if ($direct['ok'] ?? false) {
            return $direct;
        }
        $bestFailure = $direct;

        // Then: recurse into child nodes that may have their own content.
        foreach ($node['content'] as $child) {
            $nested = static::analyzeBardNode($child, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
            if ($nested['ok'] ?? false) {
                return $nested;
            }
            $bestFailure = AnchorPositionFinder::pickWorseFailure($bestFailure, $nested);
        }

        return $bestFailure;
    }

    /**
     * Replicator dry-run mirroring {@see processReplicatorWithHref}.
     *
     * @return array{ok:bool, reason?:string, blocking_href?:string}
     */
    /**
     * @see ReplicatorLinkRouter::canInsertLinkIntoReplicator — implementation home post-REV-OB-03 Phase B.
     */
    public static function canInsertLinkIntoReplicator(array $sets, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): array
    {
        return ReplicatorLinkRouter::canInsertLinkIntoReplicator($sets, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
    }

    /**
     * @see MarkdownLinkInserter::insertAllLinksIntoMarkdown — implementation home post-REV-OB-03 Phase A.
     */
    public static function insertAllLinksIntoMarkdown(string $markdown, string $anchorText, string $href, bool $caseSensitive = false): ?string
    {
        return MarkdownLinkInserter::insertAllLinksIntoMarkdown($markdown, $anchorText, $href, $caseSensitive);
    }

    /**
     * @see MarkdownLinkInserter::insertLinkIntoMarkdown — implementation home post-REV-OB-03 Phase A.
     */
    public static function insertLinkIntoMarkdown(string $markdown, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?string
    {
        return MarkdownLinkInserter::insertLinkIntoMarkdown($markdown, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
    }

    /**
     * @see ReplicatorLinkRouter::processReplicatorWithHref — implementation home post-REV-OB-03 Phase B.
     */
    public static function processReplicatorWithHref(array $sets, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?array
    {
        return ReplicatorLinkRouter::processReplicatorWithHref($sets, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
    }

    /**
     * Process a single ProseMirror node, looking for anchor text in its children.
     * Returns the modified node, or null if not found.
     */
    protected static function processNode(array $node, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?array
    {
        // Don't recurse into nodes whose contents must stay untouched. Code blocks
        // are the obvious one — wrapping inline links inside SQL/JS code corrupts
        // the rendered output. Replicator 'set' nodes have their own walker.
        if (in_array($node['type'] ?? '', ['set', 'codeBlock', 'code_block', 'horizontalRule', 'horizontal_rule', 'image'], true)) {
            return null;
        }

        // Process nodes with content (paragraph, heading, etc.)
        if (isset($node['content']) && is_array($node['content'])) {
            $result = static::findAndLinkInChildren($node['content'], $anchorText, $href, $caseSensitive, $expectedSentenceContext);

            if ($result !== null) {
                $node['content'] = $result;

                return $node;
            }

            // Recurse into child nodes that may have their own content
            foreach ($node['content'] as $j => $child) {
                $result = static::processNode($child, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

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
     *
     * Validation logic (word-boundary, context-fingerprint, already-linked
     * guard) lives in {@see AnchorPositionFinder::find()} — both the mutating
     * walker (this method) and the dry-run preview ({@see canInsertLinkIntoEntry})
     * share it so they can't drift out of sync silently.
     */
    protected static function findAndLinkInChildren(array $children, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?array
    {
        $match = AnchorPositionFinder::find($children, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

        if (! ($match['ok'] ?? false)) {
            return null;
        }

        $anchorLen = mb_strlen($anchorText);
        $startNodeIndex = $match['startNodeIndex'];
        $endNodeIndex = $match['endNodeIndex'];
        $startOffset = $match['startOffset'];
        $endOffset = $match['endOffset'];

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
     * Remove all link marks from a marks array (to prevent duplicates).
     */
    protected static function stripLinkMarks(array $marks): array
    {
        return array_values(array_filter($marks, fn ($m) => ($m['type'] ?? '') !== 'link'));
    }

    // ─── Position-based insert (Commit B of Bug 17–20 root refactor) ─────
    //
    // The methods below take an EXPLICIT (paragraph_path, char_start, char_end)
    // position — computed by Step A (UrlReplacer) — and wrap the text at that
    // exact location with a link mark. NO find-first-walker, NO sentence-context
    // fingerprint, NO anchor-fingerprint guard. The position IS the truth.
    //
    // Used only by RelinkService Step C. Other insertion paths (AutoLink,
    // ApplyRule, OutboundController.insert, InboundController.insert) keep
    // the existing find-first walker because they have no prior Step A —
    // they ARE the first walk over the content. Different problem, different
    // tradeoffs. See bug_18_19_architecture_debt memo.

    /**
     * Insert a link mark at a specific (paragraph, char range) position
     * inside a Bard content tree.
     *
     * @param  list<int>  $paragraphPath  Indices into $bardContent reaching the paragraph
     * @param  int  $charStart  byte/char offset in the paragraph's concatenated text
     * @param  int  $charEnd    exclusive end offset
     *
     * @return array{ok: bool, content?: array, reason?: string, blocking_href?: string}
     */
    public static function insertLinkAtPositionInBard(array $bardContent, string $anchorText, string $href, array $paragraphPath, int $charStart, int $charEnd): array
    {
        if ($charStart < 0 || $charEnd <= $charStart) {
            return ['ok' => false, 'reason' => 'invalid_position'];
        }

        // Navigate path to the paragraph, by-reference so we can mutate in-place.
        $cursor = &$bardContent;
        $pathDepth = count($paragraphPath);
        for ($i = 0; $i < $pathDepth - 1; $i++) {
            $segment = $paragraphPath[$i];
            if (! isset($cursor[$segment]['content']) || ! is_array($cursor[$segment]['content'])) {
                return ['ok' => false, 'reason' => 'invalid_position'];
            }
            $cursor = &$cursor[$segment]['content'];
        }
        $lastSegment = $paragraphPath[$pathDepth - 1];
        if (! isset($cursor[$lastSegment])) {
            return ['ok' => false, 'reason' => 'invalid_position'];
        }
        $paragraph = $cursor[$lastSegment];

        $children = $paragraph['content'] ?? [];

        // Map char_start / char_end to (startNodeIndex, startOffset, endNodeIndex, endOffset).
        // Same coordinate scheme as findValidMatchPosition uses: accumulate
        // mb_strlen per text child; non-text children act as a hard boundary.
        $accOffset = 0;
        $startIdx = null;
        $startOff = null;
        $endIdx = null;
        $endOff = null;
        foreach ($children as $idx => $child) {
            if (! isset($child['text'])) {
                // Non-text node — if the range straddles it, refuse.
                if ($startIdx !== null && $endIdx === null) {
                    return ['ok' => false, 'reason' => 'crosses_nontext_boundary'];
                }
                continue;
            }
            $childLen = mb_strlen($child['text']);
            $childEnd = $accOffset + $childLen;

            if ($startIdx === null && $childEnd > $charStart) {
                $startIdx = $idx;
                $startOff = $charStart - $accOffset;
            }
            if ($endIdx === null && $childEnd >= $charEnd) {
                $endIdx = $idx;
                $endOff = $charEnd - $accOffset; // exclusive
                break;
            }
            $accOffset = $childEnd;
        }

        if ($startIdx === null || $endIdx === null) {
            return ['ok' => false, 'reason' => 'out_of_range'];
        }

        // Already-linked guard: every text child the new mark would touch
        // must NOT carry an existing link mark. Same invariant the find-
        // first walker enforces — we just check it directly here.
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $child = $children[$i] ?? null;
            if (! isset($child['marks'])) {
                continue;
            }
            foreach ($child['marks'] as $mark) {
                if (($mark['type'] ?? '') !== 'link') {
                    continue;
                }
                $blockingHref = $mark['attrs']['href'] ?? '';
                $reason = $blockingHref === $href ? 'already_linked_to_target' : 'crosses_existing_link';

                return ['ok' => false, 'reason' => $reason, 'blocking_href' => $blockingHref];
            }
        }

        $attrs = ['href' => $href];
        try {
            if (\Illuminate\Support\Facades\Config::get('linkwise.open_in_new_tab', false)) {
                $attrs['target'] = '_blank';
            }
        } catch (\Throwable) {
            // Config not available (unit tests)
        }
        $linkMark = ['type' => 'link', 'attrs' => $attrs];

        $anchorLen = $charEnd - $charStart;
        if ($startIdx === $endIdx) {
            $modifiedChildren = static::splitSingleNode($children, $startIdx, $startOff, $anchorLen, $linkMark);
        } else {
            $modifiedChildren = static::linkAcrossNodes($children, $startIdx, $startOff, $endIdx, $endOff, $linkMark);
        }

        $paragraph['content'] = $modifiedChildren;
        $cursor[$lastSegment] = $paragraph;

        return ['ok' => true, 'content' => $bardContent];
    }

    /**
     * @see ReplicatorLinkRouter::insertLinkAtPositionInReplicator — implementation home post-REV-OB-03 Phase B.
     */
    public static function insertLinkAtPositionInReplicator(array $sets, string $anchorText, string $href, array $replicatorPath, array $paragraphPath, int $charStart, int $charEnd): array
    {
        return ReplicatorLinkRouter::insertLinkAtPositionInReplicator($sets, $anchorText, $href, $replicatorPath, $paragraphPath, $charStart, $charEnd);
    }

    /**
     * @see MarkdownLinkInserter::insertLinkAtPositionInMarkdown — implementation home post-REV-OB-03 Phase A.
     */
    public static function insertLinkAtPositionInMarkdown(string $markdown, string $anchorText, string $href, int $charStart, int $charEnd): array
    {
        return MarkdownLinkInserter::insertLinkAtPositionInMarkdown($markdown, $anchorText, $href, $charStart, $charEnd);
    }
}
