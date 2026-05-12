<?php

namespace Arturrossbach\Linkwise\UrlChanger;

use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\ProseMirrorTypes;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Statamic\Facades\Entry;

class UrlReplacer
{
    protected string $mode = 'smart';

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Preview which entries contain links matching the search term.
     *
     * @return array{search: string, replace: string, total_replacements: int, entries: array}
     */
    public function preview(string $search, string $replace): array
    {
        return $this->process($search, $replace);
    }

    /**
     * Apply selected replacements with precise targeting.
     * Each replacement: [
     *   'entry_id' => string,
     *   'field' => string,        // field handle
     *   'field_type' => string,   // 'bard', 'replicator', 'markdown'
     *   'matched_url' => string,  // exact href to find
     *   'occurrence_index' => int, // which occurrence in this field
     *   'new_url' => string,
     * ]
     *
     * @return array{total_replacements: int, entries_modified: int}
     */
    public function applySelected(string $search, array $replacements): array
    {
        // Group by entry_id to save each entry only once
        $byEntry = [];
        foreach ($replacements as $r) {
            $byEntry[$r['entry_id']][] = $r;
        }

        $totalReplacements = 0;
        $entriesModified = 0;

        foreach ($byEntry as $entryId => $entryReplacements) {
            [$entry, $hash] = SafeEntrySaver::load($entryId);
            if (! $entry) {
                continue;
            }

            try {
                $entry->blueprint()->fields()->all();
            } catch (\Throwable) {
                continue;
            }

            $modified = false;

            // Sort by occurrence_index descending so replacing doesn't shift later indices
            usort($entryReplacements, fn ($a, $b) => ($b['occurrence_index'] ?? 0) <=> ($a['occurrence_index'] ?? 0));

            foreach ($entryReplacements as $replacement) {
                $oldUrl = $replacement['matched_url'];
                $newUrl = $replacement['new_url'];
                $index = (int) ($replacement['occurrence_index'] ?? 0);
                // Per-replacement search allows batching items with different URLs
                $effectiveSearch = $replacement['search'] ?? $search;
                // Anchor-fingerprint guard: scan recorded which text the link
                // wrapped. Pre-flight checks index + url, but if the user
                // moved the link to wrap a different text (or another link
                // with the same URL was added), occurrence_index alone matches
                // the wrong link. Empty/missing → no anchor check (legacy
                // callers that didn't capture anchor_text in the scan).
                $expectedAnchor = ! empty($replacement['anchor_text']) ? (string) $replacement['anchor_text'] : null;

                // If field/field_type provided, target that specific field.
                // Otherwise, auto-detect by scanning all blueprint fields.
                $fieldsToCheck = [];
                if (! empty($replacement['field']) && ! empty($replacement['field_type'])) {
                    $fieldsToCheck[] = ['handle' => $replacement['field'], 'type' => $replacement['field_type']];
                } else {
                    try {
                        foreach ($entry->blueprint()->fields()->all() as $handle => $field) {
                            $fieldsToCheck[] = ['handle' => $handle, 'type' => $field->type()];
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }

                foreach ($fieldsToCheck as $fieldInfo) {
                    $handle = $fieldInfo['handle'];
                    $fieldType = $fieldInfo['type'];
                    $value = $entry->get($handle);

                    if ($fieldType === 'bard' && is_array($value)) {
                        [$value, $replaced] = $this->replaceNthInBard($value, $effectiveSearch, $oldUrl, $newUrl, $index, $expectedAnchor);
                        if ($replaced) {
                            $entry->set($handle, $value);
                            $modified = true;
                            $totalReplacements++;

                            break;
                        }
                    } elseif ($fieldType === 'replicator' && is_array($value)) {
                        [$value, $replaced] = $this->replaceNthInReplicator($value, $effectiveSearch, $oldUrl, $newUrl, $index, $expectedAnchor);
                        if ($replaced) {
                            $entry->set($handle, $value);
                            $modified = true;
                            $totalReplacements++;

                            break;
                        }
                    } elseif ($fieldType === 'markdown' && is_string($value)) {
                        [$value, $replaced] = $this->replaceNthInMarkdown($value, $oldUrl, $newUrl, $index, $expectedAnchor);
                        if ($replaced) {
                            $entry->set($handle, $value);
                            $modified = true;
                            $totalReplacements++;

                            break;
                        }
                    }
                }
            }

            if ($modified) {
                SafeEntrySaver::save($entry, $hash);
                $entriesModified++;
            }
        }

        return [
            'total_replacements' => $totalReplacements,
            'entries_modified' => $entriesModified,
        ];
    }

    /**
     * Replace the Nth matching link in Bard content.
     * Uses the same domain-based matching as findInBard so indices align.
     *
     * @param  string  $search           The original search term (domain or URL) used in preview
     * @param  string  $oldUrl           The exact href of the link to replace
     * @param  string|null  $expectedAnchor  When set, the link's wrapping text-node text MUST
     *                                       equal this string. Mismatch → skip without mutation.
     *                                       This is the anchor-fingerprint guard: scan captures
     *                                       anchor "Original" → user moves the link to a node
     *                                       wrapping "Different" → without this check, the index
     *                                       still matches (occurrence_index=0 is the only URL
     *                                       match) and the system silently unlinks the wrong
     *                                       text. With this check, the skip + clear error
     *                                       surfaces and the user re-scans.
     * @return array{0: array, 1: bool}
     */
    public function replaceNthInBard(array $bardContent, string $search, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $counter = ['i' => 0, 'replaced' => false, 'actually_replaced' => false];

        foreach ($bardContent as $i => $node) {
            $bardContent[$i] = $this->replaceNthInNode($node, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor);
            if ($counter['actually_replaced']) {
                return [$bardContent, true];
            }
        }

        return [$bardContent, false];
    }

    protected function replaceNthInNode(array $node, string $search, string $oldUrl, string $newUrl, int $targetIndex, array &$counter, ?string $expectedAnchor = null): array
    {
        if ($counter['replaced']) {
            return $node;
        }

        if (isset($node['marks'])) {
            foreach ($node['marks'] as $j => $mark) {
                if (($mark['type'] ?? '') === 'link') {
                    $href = $mark['attrs']['href'] ?? '';
                    if ($this->hrefMatches($href, $search)) {
                        if ($counter['i'] === $targetIndex) {
                            // Anchor-fingerprint guard. If the scan recorded
                            // the link as wrapping "Original" but this node
                            // wraps "Different", the user is looking at stale
                            // data — refuse to mutate. occurrence_index alone
                            // can't distinguish between "the same link, moved"
                            // and "a different link with the same URL", which
                            // is the same thing once the user only has ONE
                            // link to that URL: the index matches no matter
                            // where they moved it.
                            $nodeText = (string) ($node['text'] ?? '');
                            // Trim both sides — the guard's intent is
                            // semantic ("scan recorded X, node wraps Y
                            // ≠ X"). The indexer normalises anchors
                            // (trim, whitespace-collapse), Bard text-
                            // nodes preserve raw bytes. Byte-exact
                            // false-positives on legit re-links where
                            // the marked text-node carries trailing
                            // whitespace (Bug 17 follow-up 2026-05-12).
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
                $node['content'][$i] = $this->replaceNthInNode($child, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor);
                if ($counter['replaced']) {
                    return $node;
                }
            }
        }

        return \Arturrossbach\Linkwise\Support\BardWalker::mapSetChildren(
            $node,
            fn (array $child) => $this->replaceNthInNode($child, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor),
            fn (): bool => $counter['replaced'],
        );
    }

    /**
     * Replace the Nth link matching oldUrl in Replicator content.
     * Uses a shared counter across all nested Bard fields (same traversal order as findInReplicator).
     *
     * @return array{0: array, 1: bool}
     */
    public function replaceNthInReplicator(array $sets, string $search, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $counter = ['i' => 0, 'replaced' => false, 'actually_replaced' => false];
        $sets = $this->replaceNthInReplicatorRecursive($sets, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor);

        return [$sets, $counter['actually_replaced']];
    }


    protected function replaceNthInReplicatorRecursive(array $sets, string $search, string $oldUrl, string $newUrl, int $targetIndex, array &$counter, ?string $expectedAnchor = null): array
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
                        $value[$ni] = $this->replaceNthInNode($node, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor);
                        if ($counter['replaced']) {
                            $sets[$i][$key] = $value;

                            return $sets;
                        }
                    }
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $sets[$i][$key] = $this->replaceNthInReplicatorRecursive($value, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor);
                    if ($counter['replaced']) {
                        return $sets;
                    }
                }
            }
        }

        return $sets;
    }

    /**
     * Replace the Nth Markdown link matching oldUrl.
     *
     * @return array{0: string, 1: bool}
     */
    public function replaceNthInMarkdown(string $markdown, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $counter = 0;
        $actuallyReplaced = false;
        $escaped = preg_quote($oldUrl, '#');

        $result = preg_replace_callback(
            '#\[([^\[\]]*)\]\('.$escaped.'\)#',
            function ($match) use ($newUrl, $targetIndex, $expectedAnchor, &$counter, &$actuallyReplaced) {
                if ($counter === $targetIndex) {
                    $counter++;
                    // Anchor-fingerprint guard. See replaceNthInBard rationale.
                    // Trim both sides — the indexer normalises, raw markdown
                    // bytes don't. Byte-exact false-positives on legit
                    // re-links with trailing-whitespace anchors. Mirror of
                    // the trim-compare in replaceNthInNode.
                    if ($expectedAnchor !== null && trim($match[1]) !== trim($expectedAnchor)) {
                        return $match[0]; // hit position, but anchor differs — skip
                    }
                    $actuallyReplaced = true;

                    if ($newUrl === UrlHelper::UNLINK) {
                        return $match[1];
                    }

                    return '['.$match[1].']('.$newUrl.')';
                }
                $counter++;

                return $match[0];
            },
            $markdown,
        );

        return [$result, $actuallyReplaced];
    }

    /**
     * Extract the domain from a URL for matching purposes.
     */
    public static function extractDomain(string $url): ?string
    {
        return UrlHelper::extractDomain($url);
    }

    /**
     * Check if a href matches the search term.
     *
     * Strategy:
     * 1. Domain match: "google.com" finds all links to google.com
     * 2. Domain + path match: "google.com/page" matches that path prefix
     * 3. Substring fallback: "thesun" or "/foo-bar" matches any href containing that text
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

        $searchDomain = self::extractDomain($search);
        $hrefDomain = self::extractDomain($href);

        // If we can extract domains from both, do domain-based matching
        if ($searchDomain && $hrefDomain && $searchDomain === $hrefDomain) {
            $searchPath = $this->extractPath($search);
            if (empty($searchPath) || $searchPath === '/') {
                return true;
            }

            return str_starts_with($this->extractPath($href), $searchPath);
        }

        // Fallback: substring match
        if (str_contains($search, '.') && $hrefDomain) {
            $searchLower = mb_strtolower(preg_replace('#^(https?://|www\.)#i', '', $search));
            $fullHost = 'www.'.$hrefDomain;

            $pos = mb_stripos($fullHost, $searchLower);
            if ($pos !== false && ($pos === 0 || $fullHost[$pos - 1] === '.')) {
                return true;
            }

            return mb_stripos($this->extractPath($href), $search) !== false;
        }

        return mb_stripos($href, $search) !== false;
    }

    /**
     * Build the replacement URL for a matched href.
     * If replace is just a domain, swap the domain but keep the path.
     * If replace is a full URL, replace everything.
     */
    public function buildReplacementUrl(string $originalHref, string $search, string $replace): string
    {
        $replacePath = $this->extractPath($replace);
        $replaceHasPath = ! empty($replacePath) && $replacePath !== '/';

        // If replace has a specific path, use it as-is
        if ($replaceHasPath) {
            // Ensure protocol
            if (! preg_match('#^[a-z][a-z0-9+\-.]*://#i', $replace)) {
                return 'https://'.$replace;
            }

            return $replace;
        }

        // Replace is just a domain — swap domain, keep original path/query/fragment
        $replaceDomain = self::extractDomain($replace);
        if (! $replaceDomain) {
            return $replace;
        }

        // Parse the original URL
        $parseable = $originalHref;
        $hadNoScheme = false;
        if (! preg_match('#^[a-z][a-z0-9+\-.]*://#i', $originalHref)) {
            $parseable = 'https://'.$originalHref;
            $hadNoScheme = true;
        }

        $parsed = parse_url($parseable);
        if (! $parsed || ! isset($parsed['host'])) {
            return $replace;
        }

        // Rebuild with new domain
        $scheme = $parsed['scheme'] ?? 'https';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        // Use www. prefix if replace input had it
        $replaceHost = $replaceDomain;
        if (preg_match('/^www\./i', $replace) || preg_match('/^https?:\/\/www\./i', $replace)) {
            $replaceHost = 'www.'.$replaceDomain;
        }

        return $scheme.'://'.$replaceHost.$port.$path.$query.$fragment;
    }

    protected function extractPath(string $url): string
    {
        $parseable = $url;
        if (! preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) {
            $parseable = 'https://'.$url;
        }

        return parse_url($parseable, PHP_URL_PATH) ?? '';
    }

    // ─── Core Process ──────────────────────────────────────────────────────────

    /**
     * Preview-only: walk every entry, count matches, do NOT save.
     *
     * The previously-supported `apply: true` mode bypassed every safety
     * layer (ContentSafetyValidator, SafeEntrySaver hash-check, cascade-
     * guard) by calling $entry->save() directly — see commit history. It
     * was dead code (only preview() called process), but the latent
     * write-path was a regression risk for any future caller. Removed
     * 2026-05-09. All actual writes must go through applySelected which
     * uses SafeEntrySaver::save and inherits the full safety stack.
     */
    protected function process(string $search, string $replace): array
    {
        $entries = Entry::all();
        $result = [
            'search' => $search,
            'replace' => $replace,
            'total_replacements' => 0,
            'entries' => [],
        ];

        foreach ($entries as $entry) {
            $entryResult = $this->processEntry($entry, $search, $replace);

            if (! empty($entryResult['occurrences'])) {
                $result['entries'][] = $entryResult;
                $result['total_replacements'] += count($entryResult['occurrences']);
            }
        }

        return $result;
    }

    protected function processEntry($entry, string $search, string $replace): array
    {
        $entryResult = [
            'id' => $entry->id(),
            'title' => $entry->get('title') ?? $entry->slug(),
            'collection' => $entry->collectionHandle(),
            'occurrences' => [],
        ];

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return $entryResult;
        }

        // Preview-only walker — no save. Apply path is applySelected (which
        // routes through SafeEntrySaver). See process() docblock above for
        // why the apply branch was removed in 2026-05-09.
        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                $occurrences = $this->findInBard($value, $search);
                $entryResult['occurrences'] = array_merge($entryResult['occurrences'], array_map(
                    fn ($o) => array_merge($o, ['field' => $handle, 'field_type' => 'bard']),
                    $occurrences,
                ));
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $occurrences = $this->findInReplicator($value, $search);
                $entryResult['occurrences'] = array_merge($entryResult['occurrences'], array_map(
                    fn ($o) => array_merge($o, ['field' => $handle, 'field_type' => 'replicator']),
                    $occurrences,
                ));
            } elseif ($field->type() === 'markdown' && is_string($value) && ! empty($value)) {
                $occurrences = $this->findInMarkdown($value, $search);
                $entryResult['occurrences'] = array_merge($entryResult['occurrences'], array_map(
                    fn ($o) => array_merge($o, ['field' => $handle, 'field_type' => 'markdown']),
                    $occurrences,
                ));
            }
        }

        // The unused $replace param is kept in the signature so existing
        // callers don't need to change. process() forwards both so the
        // preview result correctly carries 'search' + 'replace' for the
        // UI to render the diff side-by-side without recomputing.
        unset($replace);

        // Compute context per occurrence using the offset-aware walker.
        // Replaces the naive occurrence-counter that picked the wrong
        // position when the same anchor word appeared both linked and
        // unlinked in the same entry (Bug 2026-05-11). The bundle walker
        // visits links in the same depth-first order as findIn* below
        // (per field, replicator nested), so the Nth occurrence for a
        // given URL maps to the Nth bundle link with that URL.
        $bundle = \Arturrossbach\Linkwise\Support\TextExtractor::extractFromEntry($entry);
        $bundleLinksByUrl = [];
        foreach ($bundle['internal_links'] as $l) {
            $bundleLinksByUrl[$l['href']][] = $l;
        }
        foreach ($bundle['external_links'] as $l) {
            $bundleLinksByUrl[$l['url']][] = $l;
        }
        $consumed = [];
        foreach ($entryResult['occurrences'] as &$occ) {
            if (empty($occ['anchor_text'])) continue;
            $url = (string) ($occ['matched_url'] ?? '');
            $i = $consumed[$url] ?? 0;
            $consumed[$url] = $i + 1;
            $bundleLink = $bundleLinksByUrl[$url][$i] ?? null;
            if ($bundleLink !== null) {
                $ctx = ContextExtractor::extractAtOffset(
                    $bundle['text'],
                    $bundleLink['offset'],
                    mb_strlen($bundleLink['anchor_text']),
                );
                $occ['context'] = $ctx['text'] ?? '';
            } elseif (empty($occ['context'])) {
                // Defensive fallback: bundle didn't include this URL (e.g.
                // a non-standard href shape the bundle walker filters out).
                // Naive occurrence=0 lookup — correct for unique anchors,
                // graceful degradation for duplicates.
                $ctx = ContextExtractor::extractStructured($bundle['text'], $occ['anchor_text'], 120, 0);
                $occ['context'] = $ctx['text'] ?? '';
            }
        }

        return $entryResult;
    }

    // ─── Bard ──────────────────────────────────────────────────────────────────

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

    protected function findInNode(array $node, string $search, array &$occurrences, array &$counter): void
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
        foreach (\Arturrossbach\Linkwise\Support\BardWalker::setChildren($node) as $bardFragment) {
            foreach ($bardFragment as $child) {
                if (is_array($child)) {
                    $this->findInNode($child, $search, $occurrences, $counter);
                }
            }
        }
    }

    /**
     * Replace matching URLs in Bard content.
     */
    public function replaceInBard(array $bardContent, string $search, string $replace): array
    {
        foreach ($bardContent as $i => $node) {
            $bardContent[$i] = $this->replaceInNode($node, $search, $replace);
        }

        return $bardContent;
    }

    protected function replaceInNode(array $node, string $search, string $replace): array
    {
        if (isset($node['marks'])) {
            foreach ($node['marks'] as $j => $mark) {
                if (($mark['type'] ?? '') === 'link') {
                    $href = $mark['attrs']['href'] ?? '';
                    if ($this->hrefMatches($href, $search)) {
                        $node['marks'][$j]['attrs']['href'] = $this->buildReplacementUrl($href, $search, $replace);
                    }
                }
            }
        }

        if (isset($node['content'])) {
            foreach ($node['content'] as $i => $child) {
                $node['content'][$i] = $this->replaceInNode($child, $search, $replace);
            }
        }

        // Set-aware recursion: rewrite URLs inside Bard set children
        // too, mirroring findInNode above so find/replace counts stay
        // consistent when Peak Cards / pull-quotes / button labels
        // contain URLs the user wants changed.
        return \Arturrossbach\Linkwise\Support\BardWalker::mapSetChildren(
            $node,
            fn (array $child) => $this->replaceInNode($child, $search, $replace),
        );
    }

    // ─── Replicator ────────────────────────────────────────────────────────────

    public function findInReplicator(array $sets, string $search): array
    {
        $occurrences = [];
        $counter = ['i' => 0];

        $this->findInReplicatorRecursive($sets, $search, $occurrences, $counter);

        return $occurrences;
    }

    protected function findInReplicatorRecursive(array $sets, string $search, array &$occurrences, array &$counter): void
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

    public function replaceInReplicator(array $sets, string $search, string $replace): array
    {
        foreach ($sets as $i => $set) {
            if (! is_array($set)) {
                continue;
            }

            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $sets[$i][$key] = $this->replaceInBard($value, $search, $replace);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $sets[$i][$key] = $this->replaceInReplicator($value, $search, $replace);
                }
            }
        }

        return $sets;
    }

    // ─── Markdown ──────────────────────────────────────────────────────────────

    /**
     * Find all links matching the search in Markdown content.
     *
     * @return array<array{anchor_text: string, matched_url: string}>
     */
    public function findInMarkdown(string $markdown, string $search): array
    {
        $occurrences = [];
        $index = 0;

        // Match all Markdown links: [text](url). occurrence_index counts ONLY
        // hrefMatches-positives so it aligns with replaceNthInMarkdown which
        // counts the same way (oldUrl-restricted pattern only fires on matches).
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
     * Replace matching URLs in Markdown content.
     */
    public function replaceInMarkdown(string $markdown, string $search, string $replace): string
    {
        return preg_replace_callback(
            '#(\[[^\]]*\])\(([^)]+)\)#',
            function ($match) use ($search, $replace) {
                $href = $match[2];
                if ($this->hrefMatches($href, $search)) {
                    return $match[1].'('.$this->buildReplacementUrl($href, $search, $replace).')';
                }

                return $match[0];
            },
            $markdown,
        );
    }
}
