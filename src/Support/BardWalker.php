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

            // Hot-path fast-out: leaf text nodes have neither nested
            // content nor a 'set' attrs.values map. Skipping the
            // recursiveChildren generator setup for them avoids
            // ~one Generator allocation per text node visited — and
            // text nodes outnumber containers in any real Bard tree.
            $hasContent = isset($node['content']) && is_array($node['content']);
            $isSet = ($node['type'] ?? '') === 'set';
            if (! $hasContent && ! $isSet) {
                continue;
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
        // Hot-path fast-out: the vast majority of nodes a mutation
        // walker visits are NOT set nodes (paragraph, text, list, …).
        // Returning an empty array short-circuits the foreach in the
        // caller without paying for a Generator allocation per visit.
        if (($node['type'] ?? '') !== 'set') {
            return [];
        }
        if (! is_array($node['attrs']['values'] ?? null)) {
            return [];
        }
        return self::yieldSetChildren($node['attrs']['values']);
    }

    /**
     * Generator body extracted so setChildren() can early-return a
     * cheap empty array on the common non-set case without paying for
     * a generator. Generators in PHP allocate even when never iterated.
     */
    private static function yieldSetChildren(array $values): iterable
    {
        foreach ($values as $key => $val) {
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

    /**
     * Mutation helper for callers that need to recurse into a node's
     * Bard 'set' children, mutate each child, and write the mutated
     * fragment back to $node['attrs']['values'][$key].
     *
     * Replaces the four near-identical "iterate setChildren → recurse →
     * write back, with optional early-stop" blocks UrlReplacer used to
     * carry around. Adding a fifth caller becomes one method-call
     * instead of an eight-line copy-paste.
     *
     * The optional $shouldStop predicate (called after each child is
     * processed) lets early-stopping mutators (replaceNthInNode,
     * fallbackReplaceFirstByUrl) bail as soon as their counter trips —
     * see UrlReplacer for the per-call pattern.
     *
     * @param  array  $node  Bard node to walk (mutated in place via the
     *   returned copy — caller assigns it back, e.g.
     *   `$node = BardWalker::mapSetChildren($node, ...)`).
     * @param  callable(array $child): array  $childMapper  Per-child
     *   transformer. Receives one node at a time, returns the mutated
     *   form. Side-effects on shared counters/refs are the caller's
     *   responsibility.
     * @param  ?callable(): bool  $shouldStop  Early-stop predicate. When
     *   present and returning true after a mapper run, the helper writes
     *   back the partially-mutated fragment and returns $node immediately.
     */
    public static function mapSetChildren(array $node, callable $childMapper, ?callable $shouldStop = null): array
    {
        foreach (self::setChildren($node) as $key => $bardFragment) {
            $newFragment = $bardFragment;
            foreach ($bardFragment as $i => $child) {
                if (! is_array($child)) {
                    continue;
                }
                $newFragment[$i] = $childMapper($child);
                if ($shouldStop !== null && $shouldStop()) {
                    $node['attrs']['values'][$key] = $newFragment;
                    return $node;
                }
            }
            $node['attrs']['values'][$key] = $newFragment;
        }
        return $node;
    }

    /**
     * Normalize a Bard children-array: merge adjacent text nodes with
     * identical mark-set into a single text node.
     *
     * Why this exists: Linkwise's link-insertion path can produce two
     * adjacent text nodes that carry the same link-mark (e.g. when a
     * match spans nodes that were already split by a prior mutation —
     * `BardLinkInserter::linkAcrossNodes` wraps each node separately,
     * not as one). Statamic Bard does NOT normalize this on save
     * (verified 2026-05-11 via save-roundtrip with no changes — file
     * bytes identical, fragmented marks persist). The two divergent
     * views ("display sees one merged link, walker sees two") cause
     * silent NO-OPs: URL-Changer apply with merged anchor mismatches
     * the per-node anchor-fingerprint guard and reports
     * total_replacements=0 while looking successful.
     *
     * Invariant enforced here: "no children-array contains two adjacent
     * text nodes with the same mark-set". Calling this after every
     * mutation — and as a final pass in SafeEntrySaver::save —
     * collapses fragments to their canonical form before they reach
     * disk or any downstream walker.
     *
     * What gets merged:
     *   - Two adjacent text nodes whose mark arrays contain the same
     *     marks (order-agnostic comparison; mark = type+attrs).
     *   - Including the no-marks case: plain "Hello" + plain " world"
     *     → "Hello world". This is the case left behind by unlink
     *     mutations that strip a mark but don't remerge the surrounding
     *     plain-text siblings.
     *
     * What does NOT get merged:
     *   - text nodes separated by any non-text node (hardBreak, image, …).
     *   - text nodes with differing mark-sets (different href, additional
     *     bold-mark on one side, etc.) — preserving semantics.
     *
     * Recursion:
     *   - Recurses into $node['content'] for any container node.
     *   - Recurses into Bard 'set' attrs.values for set nodes (consistent
     *     with the rest of BardWalker — sets carry nested Bard fragments
     *     that need the same invariant).
     *   - codeBlock content is left untouched (opaque to Linkwise, same
     *     contract as the other walkers here).
     *
     * Pure: returns a new array, does not mutate input. Safe to call
     * with empty input (returns empty).
     *
     * @param  array  $children  A Bard children array — either the top-
     *                           level Bard tree, or any node's
     *                           $node['content'] sub-array.
     * @return array             Normalized children — same structure,
     *                           with adjacent same-mark text nodes merged.
     */
    public static function normalizeChildren(array $children): array
    {
        // First pass: recurse into each child so nested content is normalized
        // BEFORE we compare siblings at this level. Bottom-up keeps the
        // invariant holding all the way down.
        foreach ($children as $i => $child) {
            if (! is_array($child)) {
                continue;
            }

            $type = $child['type'] ?? '';

            // codeBlock content is opaque to Linkwise — same skip-contract
            // used by BardLinkInserter (line 86) and the other walkers.
            if (in_array($type, ['codeBlock', 'code_block'], true)) {
                continue;
            }

            if (isset($child['content']) && is_array($child['content'])) {
                $children[$i]['content'] = self::normalizeChildren($child['content']);
            }

            // Bard 'set' nodes carry nested Bard fragments under attrs.values.
            // Normalize each one — same invariant must hold inside set
            // bodies (Peak Cards, pull-quotes, accordions).
            if ($type === 'set' && isset($child['attrs']['values']) && is_array($child['attrs']['values'])) {
                foreach ($child['attrs']['values'] as $key => $val) {
                    if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                        continue;
                    }
                    if (! is_array($val) || empty($val)) {
                        continue;
                    }
                    if (ProseMirrorTypes::looksLikeBardContent($val)) {
                        $children[$i]['attrs']['values'][$key] = self::normalizeChildren($val);
                    }
                }
            }
        }

        // Second pass: merge adjacent text-node siblings at THIS level.
        return self::mergeAdjacentTextNodes($children);
    }

    /**
     * Merge runs of adjacent text nodes with identical mark-sets into
     * single text nodes. Single-level operation — does not recurse.
     * The public entry point {@see normalizeChildren()} handles recursion.
     */
    protected static function mergeAdjacentTextNodes(array $children): array
    {
        if (count($children) < 2) {
            return $children;
        }

        $out = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                $out[] = $child;
                continue;
            }

            $lastIdx = count($out) - 1;
            $prev = $lastIdx >= 0 && is_array($out[$lastIdx]) ? $out[$lastIdx] : null;

            $bothText = $prev !== null
                && ($prev['type'] ?? '') === 'text'
                && ($child['type'] ?? '') === 'text'
                && isset($prev['text'])
                && isset($child['text']);

            if ($bothText && self::sameMarkSet($prev['marks'] ?? [], $child['marks'] ?? [])) {
                $out[$lastIdx]['text'] = $prev['text'] . $child['text'];
                continue;
            }

            $out[] = $child;
        }

        return $out;
    }

    /**
     * Two mark-arrays are equivalent when they contain the same marks
     * regardless of order. A "mark" is identified by (type, attrs) —
     * canonicalized via sorted-key JSON so attrs-order doesn't fool
     * the comparison.
     *
     * Why order-agnostic: ProseMirror nominally orders marks but in
     * practice different code paths (Linkwise, Statamic CP, third-party
     * editors) emit them in different orders for the same logical state.
     * A bold+link text node should merge with a link+bold text node —
     * they render identically.
     */
    protected static function sameMarkSet(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        if (empty($a)) {
            return true;
        }

        $canonicalize = function (array $marks): array {
            $signatures = [];
            foreach ($marks as $m) {
                if (! is_array($m)) {
                    // Non-array entry — treat as distinct opaque value.
                    $signatures[] = '__nonarray__'.serialize($m);
                    continue;
                }
                $type = (string) ($m['type'] ?? '');
                $attrs = $m['attrs'] ?? [];
                if (is_array($attrs)) {
                    ksort($attrs);
                    $attrsJson = json_encode($attrs);
                } else {
                    $attrsJson = '__nonarray_attrs__';
                }
                $signatures[] = $type.'|'.$attrsJson;
            }
            sort($signatures);
            return $signatures;
        };

        return $canonicalize($a) === $canonicalize($b);
    }
}
