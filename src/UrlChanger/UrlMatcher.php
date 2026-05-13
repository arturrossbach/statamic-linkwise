<?php

namespace Arturrossbach\Linkwise\UrlChanger;

use Arturrossbach\Linkwise\Support\BardWalker;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\ProseMirrorTypes;
use Arturrossbach\Linkwise\Support\UrlHelper;

/**
 * Find-only walker family for URL-Changer.
 *
 * REV-UC-01 Phase A (2026-05-13): extracted from UrlReplacer's Walker-
 * Trinity. The trinity is:
 *   1. Find-only         → THIS class (no mutation, returns occurrences)
 *   2. Replace-only      → UrlReplacer (bulk find+replace via process)
 *   3. ReplaceNth-with-position → UrlReplacer (used by Re-Link Step A)
 *
 * Splitting reduces a 964-line god-class's surface and separates the
 * concerns. The Find-only family is the lowest-risk to extract because
 * it has no write-path coupling.
 *
 * Instance state ($mode) mirrors UrlReplacer so href-matching semantics
 * stay byte-identical. Consumers that already hold a UrlReplacer can
 * keep using its find* methods — UrlReplacer delegates to UrlMatcher
 * internally for backward compatibility.
 */
class UrlMatcher
{
    protected string $mode = 'smart';

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Find all link marks matching the search in Bard content.
     * Each occurrence gets a sequential index for targeted replacement.
     *
     * @return array<array{anchor_text: string, matched_url: string, occurrence_index: int}>
     */
    public function findInBard(array $bardContent, string $search): array
    {
        $occurrences = [];
        $counter = ['i' => 0];

        foreach ($bardContent as $node) {
            $this->findInNode($node, $search, $occurrences, $counter);
        }

        return $occurrences;
    }

    /**
     * Internal node walker. Public so UrlReplacer can pass its shared
     * counter through during cross-family operations (find+capture).
     */
    public function findInNode(array $node, string $search, array &$occurrences, array &$counter): void
    {
        if (isset($node['marks'])) {
            foreach ($node['marks'] as $mark) {
                if (($mark['type'] ?? '') === 'link') {
                    $href = $mark['attrs']['href'] ?? '';
                    if ($this->hrefMatches($href, $search)) {
                        $occurrences[] = [
                            'anchor_text' => $node['text'] ?? '',
                            'matched_url' => $href,
                            'occurrence_index' => $counter['i'],
                        ];
                        $counter['i']++;
                    }
                }
            }
        }

        if (isset($node['content'])) {
            foreach ($node['content'] as $child) {
                $this->findInNode($child, $search, $occurrences, $counter);
            }
        }

        // Bard 'set' nodes (Peak Card, pull-quote, button) carry their
        // fields under attrs.values. Without walking these, URLs linked
        // inside set-nested Bard fragments were invisible to URL-Changer:
        // preview showed N occurrences, apply rewrote N occurrences,
        // user thought "all good" — but the URLs inside Peak Cards
        // remained at the old href, silently. Symmetric set-walk added
        // here AND in replace*InNode below so find/replace stay in sync.
        foreach (BardWalker::setChildren($node) as $bardFragment) {
            foreach ($bardFragment as $child) {
                if (is_array($child)) {
                    $this->findInNode($child, $search, $occurrences, $counter);
                }
            }
        }
    }

    public function findInReplicator(array $sets, string $search): array
    {
        $occurrences = [];
        $counter = ['i' => 0];

        $this->findInReplicatorRecursive($sets, $search, $occurrences, $counter);

        return $occurrences;
    }

    /**
     * Public so UrlReplacer can pass its shared counter through during
     * cross-family Replicator-walks.
     */
    public function findInReplicatorRecursive(array $sets, string $search, array &$occurrences, array &$counter): void
    {
        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    // Use the shared counter across all nested Bard fields
                    foreach ($value as $node) {
                        $this->findInNode($node, $search, $occurrences, $counter);
                    }
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $this->findInReplicatorRecursive($value, $search, $occurrences, $counter);
                }
            }
        }
    }

    /**
     * Find all matching links in a Markdown string.
     *
     * occurrence_index counts ONLY hrefMatches-positives so it aligns with
     * replaceNthInMarkdown (which counts the same way — oldUrl-restricted
     * pattern only fires on matches).
     *
     * @return array<array{anchor_text: string, matched_url: string, occurrence_index: int, context: array}>
     */
    public function findInMarkdown(string $markdown, string $search): array
    {
        $occurrences = [];
        $index = 0;

        if (preg_match_all('#\[([^\[\]]*)\]\(([^)]+)\)#', $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $href = $match[2];
                if ($this->hrefMatches($href, $search)) {
                    $occurrences[] = [
                        'anchor_text' => $match[1],
                        'matched_url' => $href,
                        'occurrence_index' => $index,
                        'context' => ContextExtractor::extract($markdown, $match[1]),
                    ];
                    $index++;
                }
            }
        }

        return $occurrences;
    }

    /**
     * Smart-vs-exact href matching used by all find* methods.
     *
     * The same semantics as UrlReplacer::hrefMatches — kept in sync.
     */
    public function hrefMatches(string $href, string $search): bool
    {
        // Empty search means "list all links" — independent of mode. Without
        // this short-circuit, exact-mode + empty search would compare every
        // href against '' and match nothing, producing a confusing empty list
        // when the user just wanted to see everything.
        if ($search === '') {
            // Same exclusions as smart mode — internal-only protocols don't
            // belong in a "all links" view.
            return ! str_starts_with($href, 'mailto:')
                && ! str_starts_with($href, 'tel:')
                && ! str_starts_with($href, '#')
                && ! str_starts_with($href, 'statamic://');
        }

        // Exact mode: simple string comparison
        if ($this->mode === 'exact') {
            return $href === $search;
        }

        // Smart mode (default): domain-based + substring fallback
        if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, '#')) {
            return false;
        }

        if (str_starts_with($href, 'statamic://') || str_starts_with($search, 'statamic://')) {
            return str_starts_with($search, 'statamic://') && $href === $search;
        }

        $searchDomain = UrlHelper::extractDomain($search);
        $hrefDomain = UrlHelper::extractDomain($href);

        // If we can extract domains from both, do domain-based matching
        if ($searchDomain && $hrefDomain && $searchDomain === $hrefDomain) {
            $searchPath = static::extractPath($search);
            if (empty($searchPath) || $searchPath === '/') {
                return true;
            }

            return str_starts_with(static::extractPath($href), $searchPath);
        }

        // Fallback: substring match
        if (str_contains($search, '.') && $hrefDomain) {
            $searchLower = mb_strtolower(preg_replace('#^(https?://|www\.)#i', '', $search));
            $fullHost = 'www.'.$hrefDomain;

            $pos = mb_stripos($fullHost, $searchLower);
            if ($pos !== false && ($pos === 0 || $fullHost[$pos - 1] === '.')) {
                return true;
            }

            return mb_stripos(static::extractPath($href), $search) !== false;
        }

        return mb_stripos($href, $search) !== false;
    }

    /**
     * Pure URL-path extraction. Static + free of instance state so callers
     * outside the Matcher can use it directly.
     */
    public static function extractPath(string $url): string
    {
        $parseable = $url;
        if (! preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) {
            $parseable = 'https://'.$url;
        }

        return parse_url($parseable, PHP_URL_PATH) ?? '';
    }
}
