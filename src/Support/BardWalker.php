<?php

namespace Arturrossbach\Linkwise\Support;

/**
 * Single source of truth for "how to traverse a Bard ProseMirror tree".
 *
 * Linkwise has 6+ "is this anchor / href / keyword linked here?" checks
 * spread across 3 modules (InboundEngine, AutoLinkApplier, UrlReplacer).
 * Each one has to recurse correctly into:
 *
 *   - $node['content']                 — paragraph children, list items,
 *                                        table cells, etc. (the obvious case)
 *   - $node['attrs']['values']         — Bard custom 'set' nodes (Peak Cards,
 *                                        pull-quotes, button labels). Sits
 *                                        under attrs, not content. Easy to
 *                                        miss — and historically was missed
 *                                        in 4 different places, surfacing
 *                                        as duplicate-link insertions and
 *                                        ghost suggestions.
 *
 * Each individual checker also has to walk replicator-style nested arrays
 * (e.g. when Bard set values themselves contain Bard fragments).
 *
 * This class consolidates the traversal in one place. Two entry points:
 *
 *   walk(content, visitor)   — visitor pattern, returns true to stop walking.
 *                              Good for "does any node match X" / "find first".
 *
 *   recursiveChildren(node)  — generator yielding the nested Bard arrays
 *                              of a single node. Use for mutation walkers
 *                              that need to recurse into the same set of
 *                              children but track their own state (e.g.
 *                              UrlReplacer's per-occurrence counter).
 *
 * Both respect the same "what counts as a recursion target" contract,
 * so a fix here propagates to every call site automatically.
 */
class BardWalker
{
    /**
     * Walk every node in a Bard tree, calling $visitor on each.
     *
     * The visitor receives the raw node array. Return true to stop the
     * walk (e.g. "found what I was looking for, don't keep searching").
     * Return any other value (false / null / void) to keep walking.
     *
     * Walks $node['content'] children AND Bard 'set' attrs.values
     * children. Replicator-style nested arrays (where the value is a
     * list of {type, id, ...} sets) recurse via this method too —
     * they look like Bard content for the purposes of "is something
     * linked here", so we treat them uniformly.
     *
     * Non-array nodes are skipped silently. This keeps the walker
     * resilient against partial data (e.g. mid-edit Bard JSON) without
     * the caller needing defensive checks.
     *
     * @param  array  $content  ProseMirror node-array (the outer Bard array,
     *                          OR a node's $node['content']).
     * @param  callable(array $node): bool|void  $visitor  Returns true to stop.
     */
    public static function walk(array $content, callable $visitor): bool
    {
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }

            if ($visitor($node) === true) {
                return true;
            }

            foreach (self::recursiveChildren($node) as $children) {
                if (self::walk($children, $visitor)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Yield the child Bard arrays of a single node — the targets a walker
     * should recurse into.
     *
     * Used by walk() internally to combine content-recursion and set-
     * recursion into one stream of children. Mutation walkers that already
     * handle their own content-recursion should use setChildren() instead
     * to grab only the set-specific children.
     *
     * @return iterable<array>  Each yielded item is an array of Bard nodes.
     */
    public static function recursiveChildren(array $node): iterable
    {
        // Standard nested-content children. Paragraph→text, list→listItem,
        // table→tableRow, etc. The most common recursion target.
        if (isset($node['content']) && is_array($node['content'])) {
            yield $node['content'];
        }

        // Bard set's nested Bard fragments — same source as setChildren()
        // but yielded as values without keys (walk doesn't need to write
        // back). Mutators that need to write back use setChildren() to
        // get the key→fragment map.
        foreach (self::setChildren($node) as $fragment) {
            yield $fragment;
        }
    }

    /**
     * Yield (key, bardFragment) pairs for a Bard 'set' node's nested Bard
     * fragments — the values inside attrs.values that themselves contain
     * Bard content (set body, caption, accordion content, etc.).
     *
     * For mutation walkers (UrlReplacer's findInNode / replaceInNode /
     * replaceNthInNode and friends) that already loop over $node['content']
     * separately and need to ALSO recurse into set children. Yielding
     * key=>value lets the caller write the mutated fragment back into
     * $node['attrs']['values'][$key].
     *
     * String-typed children (button labels, headings) and metadata keys
     * (type/id/enabled) are excluded — these aren't Bard trees so a
     * mutation walker that does Bard-mark replacement has nothing to do
     * with them.
     *
     * @return iterable<string, array>  Key from attrs.values, value is a Bard fragment.
     */
    public static function setChildren(array $node): iterable
    {
        if (($node['type'] ?? '') !== 'set') {
            return;
        }
        if (! is_array($node['attrs']['values'] ?? null)) {
            return;
        }
        foreach ($node['attrs']['values'] as $key => $val) {
            if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                continue;
            }
            if (! is_array($val) || empty($val)) {
                continue;
            }
            if (ProseMirrorTypes::looksLikeBardContent($val)) {
                yield $key => $val;
            }
        }
    }
}
