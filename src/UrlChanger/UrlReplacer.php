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

    /**
     * REV-UC-01 Phase A (2026-05-13): find-only walker family lives in
     * UrlMatcher. UrlReplacer keeps its public find* methods as backward-
     * compatible pass-throughs so existing callers (audits, tests) don't
     * break. The mode is mirrored on set so both objects stay in sync.
     */
    protected UrlMatcher $matcher;

    public function __construct()
    {
        $this->matcher = new UrlMatcher;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        $this->matcher->setMode($mode);

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
     * @return array{0: array, 1: bool, 2: ?array{paragraph_path: list<int>, char_start: int, char_end: int}}
     *   3rd element: position of the unlinked anchor in the RETURNED tree.
     *   - paragraph_path: index sequence into $bardContent that locates the
     *     paragraph (or paragraph-like block) containing the unlinked anchor.
     *     For a top-level paragraph: `[5]`. For a paragraph inside a list item:
     *     `[5, 2, 0]` (bulletList[5].content[2].content[0]).
     *   - char_start / char_end: byte offsets of the anchor inside the
     *     concatenated text of that paragraph's direct text children.
     *   `null` unless `actually_replaced` is true. Consumed by Step C
     *   (insertLinkAtPosition) to re-wrap WITHOUT find-first-walker search.
     */
    public function replaceNthInBard(array $bardContent, string $search, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $counter = [
            'i' => 0,
            'replaced' => false,
            'actually_replaced' => false,
            // Position-capture (Bug 17–20 root refactor 2026-05-12):
            'captured_path' => null,     // path from $bardContent root TO the matched text-node
            'captured_text_node' => null, // the text-node's text content (for char-offset re-resolution)
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

        // The matched text-node is at $path; its parent (paragraph) is $path
        // minus the last index. Walk to the parent to compute char offset.
        $childIndex = array_pop($path);
        $parent = $bardContent;
        $node = ['content' => $bardContent]; // synthetic root for path traversal
        $segments = [];
        foreach ($path as $segment) {
            $segments[] = $segment;
        }
        // Walk into bardContent following $segments
        $cursor = ['content' => $bardContent];
        foreach ($segments as $seg) {
            if (! isset($cursor['content'][$seg])) {
                return null;
            }
            $cursor = $cursor['content'][$seg];
        }
        // $cursor is now the parent paragraph-like block
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
                                // Capture position for Step C — the text-node
                                // we just modified IS the anchor location. Path
                                // is the breadcrumb from $bardContent root down
                                // to THIS text-node. Text is the node's raw
                                // text (anchor) for char-offset reconstruction.
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

        return \Arturrossbach\Linkwise\Support\BardWalker::mapSetChildren(
            $node,
            // BardWalker::mapSetChildren is for set-node `attrs.values` — a
            // nested ProseMirror subtree. We don't extend $pathSoFar here
            // because the position-shape only supports `content` traversal;
            // matches inside set-attrs.values get a path that's not navigable
            // from $bardContent root, and Step C falls back to find-first
            // for that rare case. Documenting the limitation here.
            fn (array $child) => $this->replaceNthInNode($child, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor),
            fn (): bool => $counter['replaced'],
        );
    }

    /**
     * Replace the Nth link matching oldUrl in Replicator content.
     * Uses a shared counter across all nested Bard fields (same traversal order as findInReplicator).
     *
     * @return array{0: array, 1: bool, 2: ?array{replicator_path: list<array{set_index: int, key: string}>, paragraph_path: list<int>, char_start: int, char_end: int}}
     *   3rd element: position of the unlinked anchor.
     *   - replicator_path: breadcrumbs of (set_index, key) pairs from the top-
     *     level $sets down to the innermost Bard field whose content was
     *     modified. For Pazifisch's text_block.body Bard: `[{set_index:1, key:'body'}]`.
     *     For two_column.left Bard: `[{set_index:5, key:'left'}]`.
     *   - paragraph_path / char_start / char_end: same shape as replaceNthInBard's.
     */
    public function replaceNthInReplicator(array $sets, string $search, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $counter = [
            'i' => 0,
            'replaced' => false,
            'actually_replaced' => false,
            'captured_path' => null,
            'captured_text_node' => null,
            'captured_replicator_path' => null, // breadcrumb of (set_index, key) when match is inside a replicator-nested Bard
            'captured_bard_root' => null,        // the Bard array containing the match — needed to resolve char offset
        ];
        $sets = $this->replaceNthInReplicatorRecursive($sets, $search, $oldUrl, $newUrl, $targetIndex, $counter, $expectedAnchor, []);

        if (! $counter['actually_replaced']) {
            return [$sets, false, null];
        }

        // Resolve position from the captured Bard root + path. The Bard root
        // we captured is the POST-modification snapshot; same shape as the
        // direct-Bard case so we can reuse resolvePositionFromCapture.
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
                            // Capture the replicator-path + the Bard root that
                            // holds the modified node — Step C will need both
                            // to navigate from $sets root to the text-node.
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
     * Replace the Nth Markdown link matching oldUrl.
     *
     * @return array{0: string, 1: bool}
     */
    /**
     * @return array{0: string, 1: bool, 2: ?array{char_start: int, char_end: int}}
     *   3rd element: byte offsets in the RETURNED string where the unlinked
     *   anchor text now sits — `null` unless `actually_replaced` is true and
     *   the operation was UNLINK. Used by RelinkService Step A to tell Step C
     *   exactly where to re-wrap, eliminating the find-first-walker re-search
     *   that previously caused Bug 18/19/20.
     */
    public function replaceNthInMarkdown(string $markdown, string $oldUrl, string $newUrl, int $targetIndex, ?string $expectedAnchor = null): array
    {
        $escaped = preg_quote($oldUrl, '#');

        // preg_match_all w/ PREG_OFFSET_CAPTURE gives us byte offsets per
        // match — preg_replace_callback alone can't. We need the offset to
        // populate $position for Step C.
        if (! preg_match_all('#\[([^\[\]]*)\]\('.$escaped.'\)#', $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [$markdown, false, null];
        }
        if (! isset($matches[$targetIndex])) {
            return [$markdown, false, null];
        }

        $target = $matches[$targetIndex];
        $fullMatch = $target[0][0];
        $matchOffset = $target[0][1];
        $anchor = $target[1][0];

        // Anchor-fingerprint guard. See replaceNthInBard rationale.
        // Trim both sides — the indexer normalises, raw markdown bytes
        // don't. Byte-exact false-positives on legit re-links with
        // trailing-whitespace anchors. Mirror of the trim-compare in
        // replaceNthInNode.
        if ($expectedAnchor !== null && trim($anchor) !== trim($expectedAnchor)) {
            return [$markdown, false, null];
        }

        $replacement = $newUrl === UrlHelper::UNLINK ? $anchor : '['.$anchor.']('.$newUrl.')';
        $result = substr_replace($markdown, $replacement, $matchOffset, strlen($fullMatch));

        // Position points at where the anchor text sits in the RESULT string.
        // - UNLINK: the bare anchor replaces `[anchor](url)`, so its start is
        //   $matchOffset; length = strlen($anchor).
        // - URL-change: the anchor still sits inside the new `[anchor](newurl)`
        //   at the SAME relative offset (1 char in after the `[`).
        $anchorStartInResult = $newUrl === UrlHelper::UNLINK ? $matchOffset : $matchOffset + 1;
        $position = [
            'char_start' => $anchorStartInResult,
            'char_end' => $anchorStartInResult + strlen($anchor),
        ];

        return [$result, true, $position];
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
        // REV-UC-01 Phase A: delegated to UrlMatcher.
        return $this->matcher->hrefMatches($href, $search);
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
        // REV-UC-01 Phase A: moved to UrlMatcher (public static).
        return UrlMatcher::extractPath($url);
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
     * REV-UC-01 Phase A (2026-05-13): find-only family delegated to
     * UrlMatcher. These pass-through methods keep the public API stable
     * for existing callers (tests, audits, other internal consumers).
     */
    public function findInBard(array $bardContent, string $search): array
    {
        return $this->matcher->findInBard($bardContent, $search);
    }

    protected function findInNode(array $node, string $search, array &$occurrences, array &$counter): void
    {
        $this->matcher->findInNode($node, $search, $occurrences, $counter);
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
        return $this->matcher->findInReplicator($sets, $search);
    }

    protected function findInReplicatorRecursive(array $sets, string $search, array &$occurrences, array &$counter): void
    {
        $this->matcher->findInReplicatorRecursive($sets, $search, $occurrences, $counter);
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
        return $this->matcher->findInMarkdown($markdown, $search);
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
