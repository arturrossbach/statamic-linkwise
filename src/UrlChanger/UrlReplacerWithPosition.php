<?php

namespace Arturrossbach\Linkwise\UrlChanger;

use Arturrossbach\Linkwise\Support\BardWalker;
use Arturrossbach\Linkwise\Support\ProseMirrorTypes;
use Arturrossbach\Linkwise\Support\UrlHelper;

/**
 * Position-capturing replace family (Bug 17–20 root refactor, 2026-05-12).
 *
 * REV-UC-01 Phase B (2026-05-13): extracted from UrlReplacer's Walker-
 * Trinity. This is the family Re-Link Step A consumes: each replaceNth*
 * call returns `[$modified, $did, ?$position]` where `$position` lets
 * Step C jump directly to the unlinked anchor's coordinates instead of
 * re-walking with the old find-first heuristic (which was the original
 * source of Bug 18/19/20 — anchor wandered or refused silently).
 *
 * Uses UrlMatcher internally for href-matching so the smart-vs-exact
 * mode + REPLICATOR_META_KEYS skip-list semantics stay in sync with
 * the find-only family.
 */
class UrlReplacerWithPosition
{
    protected UrlMatcher $matcher;

    public function __construct(?UrlMatcher $matcher = null)
    {
        $this->matcher = $matcher ?? new UrlMatcher;
    }

    public function setMode(string $mode): self
    {
        $this->matcher->setMode($mode);

        return $this;
    }

    /**
     * Replace the Nth matching link in Bard content.
     * Uses the same domain-based matching as findInBard so indices align.
     *
     * @return array{0: array, 1: bool, 2: ?array{paragraph_path: list<int>, char_start: int, char_end: int}}
     *   3rd element: position of the unlinked anchor in the RETURNED tree.
     *   `null` unless `actually_replaced` is true. Consumed by Step C
     *   (insertLinkAtPosition) to re-wrap WITHOUT find-first-walker search.
     */
    public function replaceNthInBard(array $bardContent, string $search, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $counter = [
            'i' => 0,
            'replaced' => false,
            'actually_replaced' => false,
            'captured_path' => null,
            'captured_text_node' => null,
        ];

        foreach ($bardContent as $i => $node) {
            $bardContent[$i] = $this->replaceNthInNode($node, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor, [$i]);
            if ($counter['actually_replaced']) {
                $position = $this->resolvePositionFromCapture($bardContent, $counter);

                return [$bardContent, true, $position];
            }
        }

        return [$bardContent, false, null];
    }

    /**
     * Convert the captured node-path into a (paragraph_path, char_start, char_end)
     * triple. The text-node sits at the end of $capturedPath; its parent is the
     * paragraph-like block (one level up). char_start is computed by summing the
     * mb_strlen of the parent's preceding text-children.
     *
     * @return array{paragraph_path: list<int>, char_start: int, char_end: int}|null
     */
    protected function resolvePositionFromCapture(array $bardContent, array $counter): ?array
    {
        $path = $counter['captured_path'] ?? null;
        $text = $counter['captured_text_node'] ?? null;
        if ($path === null || $text === null || count($path) < 2) {
            return null;
        }

        $childIndex = array_pop($path);
        $segments = [];
        foreach ($path as $segment) {
            $segments[] = $segment;
        }
        $cursor = ['content' => $bardContent];
        foreach ($segments as $seg) {
            if (! isset($cursor['content'][$seg])) {
                return null;
            }
            $cursor = $cursor['content'][$seg];
        }
        $children = $cursor['content'] ?? [];
        $charStart = 0;
        for ($i = 0; $i < $childIndex; $i++) {
            $sibling = $children[$i] ?? null;
            if (is_array($sibling) && isset($sibling['text'])) {
                $charStart += mb_strlen($sibling['text']);
            }
        }

        return [
            'paragraph_path' => $segments,
            'char_start' => $charStart,
            'char_end' => $charStart + mb_strlen($text),
        ];
    }

    protected function replaceNthInNode(array $node, string $search, string $oldUrl, string $newUrl, int $targetIndex, array &$counter, ?string $expectedAnchor = null, array $pathSoFar = []): array
    {
        if ($counter['replaced']) {
            return $node;
        }

        if (isset($node['marks'])) {
            foreach ($node['marks'] as $j => $mark) {
                if (($mark['type'] ?? '') === 'link') {
                    $href = $mark['attrs']['href'] ?? '';
                    if ($this->matcher->hrefMatches($href, $search)) {
                        if ($counter['i'] === $targetIndex) {
                            $nodeText = (string) ($node['text'] ?? '');
                            // Anchor-fingerprint guard with trim-compare —
                            // see UrlReplacer history for rationale.
                            $anchorMismatch = $expectedAnchor !== null
                                && trim($nodeText) !== trim($expectedAnchor);
                            if (! $anchorMismatch && $href === $oldUrl) {
                                if ($newUrl === UrlHelper::UNLINK) {
                                    array_splice($node['marks'], $j, 1);
                                    if (empty($node['marks'])) {
                                        unset($node['marks']);
                                    }
                                } else {
                                    $node['marks'][$j]['attrs']['href'] = $newUrl;
                                }
                                $counter['actually_replaced'] = true;
                                $counter['captured_path'] = $pathSoFar;
                                $counter['captured_text_node'] = $nodeText;
                            }
                            $counter['replaced'] = true;

                            return $node;
                        }
                        $counter['i']++;
                    }
                }
            }
        }

        if (isset($node['content'])) {
            foreach ($node['content'] as $i => $child) {
                $node['content'][$i] = $this->replaceNthInNode($child, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor, array_merge($pathSoFar, [$i]));
                if ($counter['replaced']) {
                    return $node;
                }
            }
        }

        return BardWalker::mapSetChildren(
            $node,
            fn (array $child) => $this->replaceNthInNode($child, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor),
            fn (): bool => $counter['replaced'],
        );
    }

    /**
     * Replace the Nth link matching oldUrl in Replicator content.
     *
     * @return array{0: array, 1: bool, 2: ?array{replicator_path: list<array{set_index: int, key: string}>, paragraph_path: list<int>, char_start: int, char_end: int}}
     */
    public function replaceNthInReplicator(array $sets, string $search, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $counter = [
            'i' => 0,
            'replaced' => false,
            'actually_replaced' => false,
            'captured_path' => null,
            'captured_text_node' => null,
            'captured_replicator_path' => null,
            'captured_bard_root' => null,
        ];
        $sets = $this->replaceNthInReplicatorRecursive($sets, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor, []);

        if (! $counter['actually_replaced']) {
            return [$sets, false, null];
        }

        $bardPosition = $this->resolvePositionFromCapture($counter['captured_bard_root'], $counter);
        if ($bardPosition === null) {
            return [$sets, true, null];
        }

        return [
            $sets,
            true,
            [
                'replicator_path' => $counter['captured_replicator_path'] ?? [],
                'paragraph_path' => $bardPosition['paragraph_path'],
                'char_start' => $bardPosition['char_start'],
                'char_end' => $bardPosition['char_end'],
            ],
        ];
    }

    protected function replaceNthInReplicatorRecursive(array $sets, string $search, string $oldUrl, string $newUrl, int $targetIndex, array &$counter, ?string $expectedAnchor = null, array $replicatorPathSoFar = []): array
    {
        foreach ($sets as $i => $set) {
            if (! is_array($set) || $counter['replaced']) {
                continue;
            }

            foreach ($set as $key => $value) {
                if ($counter['replaced']) {
                    break;
                }
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    foreach ($value as $ni => $node) {
                        $value[$ni] = $this->replaceNthInNode($node, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor, [$ni]);
                        if ($counter['replaced']) {
                            $sets[$i][$key] = $value;
                            if ($counter['actually_replaced'] && $counter['captured_replicator_path'] === null) {
                                $counter['captured_replicator_path'] = array_merge(
                                    $replicatorPathSoFar,
                                    [['set_index' => $i, 'key' => $key]],
                                );
                                $counter['captured_bard_root'] = $value;
                            }

                            return $sets;
                        }
                    }
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $sets[$i][$key] = $this->replaceNthInReplicatorRecursive(
                        $value, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor,
                        array_merge($replicatorPathSoFar, [['set_index' => $i, 'key' => $key]]),
                    );
                    if ($counter['replaced']) {
                        return $sets;
                    }
                }
            }
        }

        return $sets;
    }

    /**
     * Replace the Nth Markdown link matching the user's search (smart/exact).
     *
     * Counter semantic: $targetIndex counts hrefMatches-positives across the
     * full Markdown string — same global semantic as findInMarkdown and as
     * replaceNthInBard. User-bug 2026-05-22: the pre-fix implementation used
     * a preg_quote($oldUrl)-restricted regex, which counted ONLY within
     * matches of that exact URL. When findInMarkdown returned occurrence_index
     * N because N matched links shared a domain, this method silently returned
     * "Links were already gone" for every non-first match — multi-link
     * Markdown fields were effectively un-replaceable past the first row.
     *
     * @return array{0: string, 1: bool, 2: ?array{char_start: int, char_end: int}}
     */
    public function replaceNthInMarkdown(string $markdown, string $search, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        // Match ALL markdown links, then filter through hrefMatches the same
        // way findInMarkdown does. Mirror of Bard's counter-step-then-check.
        if (! preg_match_all('#\[([^\[\]]*)\]\(([^)]+)\)#', $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [$markdown, false, null];
        }

        $counter = 0;
        foreach ($matches as $match) {
            $anchor = $match[1][0];
            $href = $match[2][0];

            if (! $this->matcher->hrefMatches($href, $search)) {
                continue;
            }

            if ($counter !== $targetIndex) {
                $counter++;
                continue;
            }

            // counter === $targetIndex: this is THE candidate. Mirror Bard's
            // per-item verification — anchor-fingerprint AND exact-href check.
            // Both gates produce silent skip (no replacement, no further
            // iteration) which preserves the user-visible "skip with reason"
            // pipeline upstream in UrlChangerApplyCommand.
            if ($expectedAnchor !== null && trim($anchor) !== trim($expectedAnchor)) {
                return [$markdown, false, null];
            }
            if ($href !== $oldUrl) {
                return [$markdown, false, null];
            }

            $fullMatch = $match[0][0];
            $matchOffset = $match[0][1];
            $replacement = $newUrl === UrlHelper::UNLINK ? $anchor : '['.$anchor.']('.$newUrl.')';
            $result = substr_replace($markdown, $replacement, $matchOffset, strlen($fullMatch));

            $anchorStartInResult = $newUrl === UrlHelper::UNLINK ? $matchOffset : $matchOffset + 1;
            $position = [
                'char_start' => $anchorStartInResult,
                'char_end' => $anchorStartInResult + strlen($anchor),
            ];

            return [$result, true, $position];
        }

        return [$markdown, false, null];
    }
}
