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
    /**
     * @param  string|null  $expectedSentenceContext  When set, the anchor MUST
     *   sit inside a text region whose surrounding text contains the supplied
     *   sentence context. This is the visual-truth guard: scan captured the
     *   anchor inside sentence X; if the user later prepended a SECOND
     *   occurrence of the anchor at the start of the entry, the naive
     *   "wrap first match" behaviour would silently wrap the new one. With
     *   the guard, the wrap only happens at the position whose surrounding
     *   text matches the captured context. Mismatch → return null.
     */
    public static function insertLinkWithHref(array $bardContent, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null, ?int $anchorOffsetInContext = null): ?array
    {
        foreach ($bardContent as $i => $node) {
            $result = static::processNode($node, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);

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

            $bestFailure = static::pickWorseFailure($bestFailure, $result);
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
    public static function canInsertLinkIntoBardContent(array $bardContent, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null, ?int $anchorOffsetInContext = null): array
    {
        $bestFailure = null;
        foreach ($bardContent as $node) {
            $result = static::analyzeBardNode($node, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);
            if ($result['ok'] ?? false) {
                return $result;
            }
            $bestFailure = static::pickWorseFailure($bestFailure, $result);
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
    protected static function analyzeBardNode(array $node, string $anchorText, string $href, bool $caseSensitive, ?string $expectedSentenceContext, ?int $anchorOffsetInContext = null): array
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
        $direct = static::findValidMatchPosition($node['content'], $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);
        if ($direct['ok'] ?? false) {
            return $direct;
        }
        $bestFailure = $direct;

        // Then: recurse into child nodes that may have their own content.
        foreach ($node['content'] as $child) {
            $nested = static::analyzeBardNode($child, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);
            if ($nested['ok'] ?? false) {
                return $nested;
            }
            $bestFailure = static::pickWorseFailure($bestFailure, $nested);
        }

        return $bestFailure;
    }

    /**
     * Replicator dry-run mirroring {@see processReplicatorWithHref}.
     *
     * @return array{ok:bool, reason?:string, blocking_href?:string}
     */
    public static function canInsertLinkIntoReplicator(array $sets, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null, ?int $anchorOffsetInContext = null): array
    {
        $bestFailure = null;

        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $result = static::canInsertLinkIntoBardContent($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $result = static::canInsertLinkIntoReplicator($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);
                } else {
                    continue;
                }

                if ($result['ok'] ?? false) {
                    return $result;
                }
                $bestFailure = static::pickWorseFailure($bestFailure, $result);
            }
        }

        return $bestFailure ?? ['ok' => false, 'reason' => 'anchor_not_found'];
    }

    /**
     * Severity comparator for dry-run failure reasons.
     *
     * Severity (high → low):
     *   crosses_existing_link > already_linked_to_target > context_mismatch > anchor_not_found
     *
     * Rationale: a "crosses_existing_link" against a DIFFERENT target is
     * the most actionable surface (toast names the blocking link).
     * "already_linked_to_target" is a no-op vs an error semantically;
     * we still surface it so the user sees they're not getting a fresh
     * wrap, but it loses to a crosses-link finding elsewhere in the
     * entry. "context_mismatch" beats "anchor_not_found" because it's
     * more specific (anchor exists somewhere, just not in the captured
     * sentence's range).
     *
     * @param  array{ok:bool, reason?:string, blocking_href?:string}|null  $current
     * @param  array{ok:bool, reason?:string, blocking_href?:string}  $candidate
     * @return array{ok:bool, reason?:string, blocking_href?:string}
     */
    protected static function pickWorseFailure(?array $current, array $candidate): array
    {
        if ($current === null) {
            return $candidate;
        }
        $rank = [
            'anchor_not_found' => 0,
            'context_mismatch' => 1,
            'already_linked_to_target' => 2,
            'crosses_existing_link' => 3,
        ];
        $rankCurrent = $rank[$current['reason'] ?? ''] ?? 0;
        $rankCandidate = $rank[$candidate['reason'] ?? ''] ?? 0;

        return $rankCandidate > $rankCurrent ? $candidate : $current;
    }

    /**
     * Insert a link into Markdown content by replacing the first occurrence of anchor text.
     */
    /**
     * Check if a match position is at word boundaries (not inside another word).
     */
    /**
     * Build a "flat" version of markdown where [anchor](url) collapses to
     * just `anchor`, plus a position map raw_offset → flat_offset (or null
     * when the raw char sits inside a [...](...) syntax fragment that's
     * not in the flat string). Used by the insertLinkIntoMarkdown context
     * guard so a sentence-context that SuggestionEngine generated from
     * the NLP-flattened text can still find its anchor occurrence inside
     * raw markdown that contains inline links (Bug 2026-05-11).
     *
     * @return array{0: string, 1: array<int, int|null>}
     */
    protected static function flattenMarkdownLinks(string $markdown): array
    {
        $flat = '';
        $rawToFlat = [];
        $rawLen = mb_strlen($markdown);
        $i = 0;
        while ($i < $rawLen) {
            $remaining = mb_substr($markdown, $i);
            // Match [anchor](url) starting at the current cursor.
            if (preg_match('/^\[([^\[\]]*)\]\(([^)]+)\)/u', $remaining, $m)) {
                $anchor = $m[1];
                $anchorLen = mb_strlen($anchor);
                $totalMatchLen = mb_strlen($m[0]);
                // The opening '[' — not in flat.
                $rawToFlat[$i] = null;
                // Anchor chars: appended to flat, each mapped.
                $flatStart = mb_strlen($flat);
                $flat .= $anchor;
                for ($k = 0; $k < $anchorLen; $k++) {
                    $rawToFlat[$i + 1 + $k] = $flatStart + $k;
                }
                // ']', '(', URL chars, ')' — all not in flat.
                $tailStart = $i + 1 + $anchorLen;
                $tailLen = $totalMatchLen - 1 - $anchorLen;
                for ($k = 0; $k < $tailLen; $k++) {
                    $rawToFlat[$tailStart + $k] = null;
                }
                $i += $totalMatchLen;
                continue;
            }
            // Regular char: copy to flat 1:1.
            $rawToFlat[$i] = mb_strlen($flat);
            $flat .= mb_substr($markdown, $i, 1);
            $i++;
        }
        return [$flat, $rawToFlat];
    }

    /**
     * When the captured sentence_context spans paragraph boundaries (legacy
     * extractContext output joined paragraphs with "\n"), the needle cannot
     * be found inside any single paragraph and the wrap silently fails. This
     * helper isolates the line that actually contains the anchor so the
     * fingerprint guard can still operate. New post-fix contexts are always
     * single-line and pass through unchanged.
     */
    protected static function narrowContextToAnchorLine(string $needle, string $anchorText): string
    {
        if ($needle === '' || ! str_contains($needle, "\n")) {
            return $needle;
        }

        $lines = explode("\n", $needle);
        foreach ($lines as $line) {
            if (mb_stripos($line, $anchorText) !== false) {
                return trim($line);
            }
        }

        // Anchor isn't in any line: leave needle unchanged so the caller's
        // mb_stripos returns false → return null → no silent wrap.
        return $needle;
    }

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

    public static function insertLinkIntoMarkdown(string $markdown, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?string
    {
        // Check if anchor text is already linked in Markdown
        $escapedAnchor = preg_quote($anchorText, '/');
        $pattern = '/\['.$escapedAnchor.'\]\(/'.($caseSensitive ? '' : 'i');
        if (preg_match($pattern, $markdown)) {
            return null; // Already linked
        }

        // Build skip-ranges for existing markdown links — both the anchor
        // text and the URL portion are off-limits. Without this, a candidate
        // matching a substring of an existing link's anchor (e.g. "development"
        // landing inside `[Modern web development](url)`) or its URL portion
        // (e.g. "statamic" landing inside `statamic://entry::uuid`) would
        // silently corrupt the content with nested `[[anchor]](url)](url)`
        // syntax. Same pattern as insertAllLinksIntoMarkdown — single-insert
        // path was missing it, the multi-insert path always had it.
        $skipRanges = [];
        if (preg_match_all('/\[[^\]]*\]\([^\)]+\)/u', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $byteOffset]) {
                $charOffset = mb_strlen(substr($markdown, 0, $byteOffset));
                $skipRanges[] = [$charOffset, $charOffset + mb_strlen($text)];
            }
        }

        // Context-fingerprint guard — see findAndLinkInChildren for full
        // rationale. When the scan captured the anchor in a specific
        // sentence, the wrap MUST land inside that sentence's range,
        // not on a different occurrence of the same anchor.
        $contextRange = null;
        if ($expectedSentenceContext !== null && $expectedSentenceContext !== '') {
            $needle = trim(str_replace(['…', '...'], '', $expectedSentenceContext));
            // Defense-in-depth: stale records may carry multi-line context
            // (cross-paragraph blob from a buggy older extractContext build).
            // Narrow to the line containing the anchor so the search can
            // succeed at all; otherwise mb_stripos returns false and the
            // wrap silently skips. New contexts (post-fix extractContext)
            // are already single-line and pass through unchanged.
            $needle = static::narrowContextToAnchorLine($needle, $anchorText);
            if ($needle !== '' && mb_strlen($needle) >= mb_strlen($anchorText)) {
                $rangeStart = mb_stripos($markdown, $needle);
                if ($rangeStart !== false) {
                    $contextRange = ['start' => $rangeStart, 'end' => $rangeStart + mb_strlen($needle)];
                } else {
                    // Fallback (Bug 2026-05-11): SuggestionEngine produces
                    // sentence_context from the NLP-flattened text (markdown
                    // links collapsed to their anchor text). When the
                    // captured sentence sits adjacent to a markdown link
                    // (e.g. "...[einem gekühlten Weißwein](url). CMS-Migration!"),
                    // the raw-markdown haystack contains the link syntax,
                    // and a literal mb_stripos for the flat needle returns
                    // false. Flatten the markdown, find the needle there,
                    // map flat-position back to raw-markdown position.
                    [$flat, $rawToFlat] = static::flattenMarkdownLinks($markdown);
                    $flatStart = mb_stripos($flat, $needle);
                    if ($flatStart === false) {
                        return null; // truly absent
                    }
                    $flatEnd = $flatStart + mb_strlen($needle);
                    $rawStart = null;
                    $rawEnd = null;
                    foreach ($rawToFlat as $rawIdx => $flatIdx) {
                        if ($flatIdx === null) continue;
                        if ($rawStart === null && $flatIdx >= $flatStart) $rawStart = $rawIdx;
                        if ($flatIdx < $flatEnd) $rawEnd = $rawIdx + 1;
                    }
                    if ($rawStart === null || $rawEnd === null) return null;
                    $contextRange = ['start' => $rawStart, 'end' => $rawEnd];
                }
            }
        }

        $anchorLen = mb_strlen($anchorText);
        $offset = 0;

        // Walk through all occurrences. Return on the first one that sits at a word boundary
        // (so "database" skips "databases" and hits the standalone "Database" next)
        // AND is not inside an existing markdown link.
        while (true) {
            $pos = $caseSensitive
                ? mb_strpos($markdown, $anchorText, $offset)
                : mb_stripos($markdown, $anchorText, $offset);

            if ($pos === false) {
                return null;
            }

            if (static::isAtWordBoundary($markdown, $pos, $anchorLen)) {
                $inSkipRange = false;
                foreach ($skipRanges as [$start, $end]) {
                    if ($pos >= $start && $pos < $end) {
                        $inSkipRange = true;
                        break;
                    }
                }

                $outsideContextRange = $contextRange !== null
                    && ($pos < $contextRange['start'] || $pos + $anchorLen > $contextRange['end']);

                if (! $inSkipRange && ! $outsideContextRange) {
                    $actualText = mb_substr($markdown, $pos, $anchorLen);
                    $before = mb_substr($markdown, 0, $pos);
                    $after = mb_substr($markdown, $pos + $anchorLen);

                    return $before.'['.$actualText.']('.$href.')'.$after;
                }
            }

            $offset = $pos + $anchorLen;
        }
    }

    /**
     * Process a Replicator field value with custom href.
     */
    /**
     * @internal Public to allow the insert-parity audit to test replicator
     * inserts in isolation without disk-mutating an entry. Production code
     * still goes through insertLinkIntoEntryWithHref.
     */
    public static function processReplicatorWithHref(array $sets, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null, ?int $anchorOffsetInContext = null): ?array
    {
        foreach ($sets as $i => $set) {
            if (! is_array($set)) {
                continue;
            }

            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                    continue;
                }

                // Plain-string fields nested in a replicator (Peak Card
                // headings, button labels, accordion plaintext bodies, …)
                // are NOT linked: at the value layer we cannot tell a
                // markdown-rendered set field apart from a plain `text`
                // field, and writing `[anchor](url)` into a plaintext
                // template surfaces as visible literal syntax. Bard
                // fragments inside the set are still walked below — those
                // carry structured link marks and are always safe.
                if (! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $modified = static::insertLinkWithHref($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);

                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $modified = static::processReplicatorWithHref($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);

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
    protected static function processNode(array $node, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null, ?int $anchorOffsetInContext = null): ?array
    {
        // Don't recurse into nodes whose contents must stay untouched. Code blocks
        // are the obvious one — wrapping inline links inside SQL/JS code corrupts
        // the rendered output. Replicator 'set' nodes have their own walker.
        if (in_array($node['type'] ?? '', ['set', 'codeBlock', 'code_block', 'horizontalRule', 'horizontal_rule', 'image'], true)) {
            return null;
        }

        // Process nodes with content (paragraph, heading, etc.)
        if (isset($node['content']) && is_array($node['content'])) {
            $result = static::findAndLinkInChildren($node['content'], $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);

            if ($result !== null) {
                $node['content'] = $result;

                return $node;
            }

            // Recurse into child nodes that may have their own content
            foreach ($node['content'] as $j => $child) {
                $result = static::processNode($child, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);

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
     * guard) lives in {@see findValidMatchPosition} — both the mutating
     * walker (this method) and the dry-run preview ({@see canInsertLinkIntoEntry})
     * share it so they can't drift out of sync silently.
     */
    protected static function findAndLinkInChildren(array $children, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null, ?int $anchorOffsetInContext = null): ?array
    {
        $match = static::findValidMatchPosition($children, $anchorText, $href, $caseSensitive, $expectedSentenceContext, $anchorOffsetInContext);

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
     * Locate the first valid occurrence of $anchorText in a children array,
     * applying the same gates the mutating walker enforces:
     *   - word-boundary on both ends
     *   - no cross of a non-text-node marker
     *   - inside the supplied sentence-context range (if provided)
     *   - no existing link mark on the affected text nodes
     *
     * Returns either an `ok=true` struct carrying the node positions the
     * caller needs to wrap, or an `ok=false` struct carrying a typed
     * failure reason so RelinkService can produce an
     * actionable error message instead of a generic "would fail".
     *
     * Reason taxonomy:
     *   - `anchor_not_found` — anchor text not present, OR only present
     *     inside a larger word (no word-boundary match).
     *   - `context_mismatch` — the captured sentence_context isn't in the
     *     current content, OR all word-boundary occurrences land outside
     *     the sentence's [start, end] range (= scan is stale).
     *   - `crosses_existing_link` — anchor would wrap text that already
     *     carries a link mark to a DIFFERENT href. `blocking_href` names
     *     the existing target so the toast can identify it.
     *   - `already_linked_to_target` — same, but the existing link points
     *     at the very target we'd insert. `blocking_href` is set, equals
     *     the requested href.
     *
     * Severity: crosses_existing_link > already_linked_to_target >
     * context_mismatch > anchor_not_found — the loop returns the most
     * actionable reason seen across all occurrences.
     *
     * @return array{ok:bool, pos?:int, startNodeIndex?:int, endNodeIndex?:int, startOffset?:int, endOffset?:int, reason?:string, blocking_href?:string}
     */
    protected static function findValidMatchPosition(array $children, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null, ?int $anchorOffsetInContext = null): array
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

        // Context-fingerprint guard: when the caller knows the sentence the
        // anchor was scanned in, the wrap MUST land at a position whose
        // surrounding text contains that sentence. If the entry now has a
        // SECOND occurrence of the anchor (e.g. user prepended one), the
        // naive "wrap first match" would silently mutate the wrong one.
        // We compute the allowed character range here once; positions
        // outside it are rejected below.
        $contextRange = null;
        if ($expectedSentenceContext !== null && $expectedSentenceContext !== '') {
            // The scan often returns sentence-context with a leading "…" /
            // ellipsis (ContextExtractor truncation). Strip those before
            // matching so a literal substring search lines up.
            $needle = trim(str_replace(['…', '...'], '', $expectedSentenceContext));
            // Defense-in-depth: stale records may carry multi-line context
            // (cross-paragraph blob from a buggy older extractContext build).
            // Narrow to the line containing the anchor so the search can
            // succeed inside a single paragraph's $fullText.
            $needle = static::narrowContextToAnchorLine($needle, $anchorText);
            if ($needle !== '' && mb_strlen($needle) >= mb_strlen($anchorText)) {
                $rangeStart = mb_stripos($fullText, $needle);
                if ($rangeStart === false) {
                    // Sentence not present in current content → scan is
                    // stale, refuse to wrap anything. Caller surfaces this
                    // as "context changed, refresh and retry".
                    return ['ok' => false, 'reason' => 'context_mismatch'];
                }
                // Bug 19 fix: when the caller knows the anchor's exact
                // character offset inside the (un-trimmed) sentence_context,
                // narrow the range to ONE position. The sentence_context is
                // a ±N-char window that can span multiple identical anchor
                // occurrences inside a paragraph (e.g. "Ich liebe X mhhhh.
                // Heute über X nachgedacht" — two "X"s inside the captured
                // window). Without this narrow, "wrap first valid match"
                // silently lands on the wrong one. We offset against the
                // raw context first (matches the offset's coordinate space),
                // then map the resulting char position into $fullText.
                //
                // We DON'T require an exact one-anchor-wide range. The
                // sentence-context narrowing already eliminated outside-
                // sentence matches; pinning to anchor-length is what
                // discriminates between two occurrences inside the same
                // sentence (which the user wouldn't see in their captured
                // context anyway).
                if ($anchorOffsetInContext !== null && $anchorOffsetInContext >= 0) {
                    // Map raw-context offset → fullText position. The raw
                    // context could differ from $needle by the leading "…/
                    // ..." we stripped above; recompute the shift so the
                    // offset stays aligned.
                    $rawContext = $expectedSentenceContext;
                    $strippedPrefix = mb_strlen($rawContext) - mb_strlen(ltrim(str_replace(['…', '...'], '', $rawContext)));
                    $offsetInNeedle = $anchorOffsetInContext - $strippedPrefix;
                    if ($offsetInNeedle >= 0 && $offsetInNeedle + mb_strlen($anchorText) <= mb_strlen($needle)) {
                        $pinned = $rangeStart + $offsetInNeedle;
                        // Single-position range — anchor must START exactly
                        // here. The downstream gate `$found < start ||
                        // $found + anchorLen > end` rejects everything else.
                        $contextRange = ['start' => $pinned, 'end' => $pinned + mb_strlen($anchorText)];
                    } else {
                        // Offset doesn't fit inside the narrowed needle —
                        // content has shifted under us, scan is stale.
                        $contextRange = ['start' => $rangeStart, 'end' => $rangeStart + mb_strlen($needle)];
                    }
                } else {
                    $contextRange = ['start' => $rangeStart, 'end' => $rangeStart + mb_strlen($needle)];
                }
            }
        }

        $anchorLen = mb_strlen($anchorText);
        $offset = 0;
        $bestFailure = null; // most-informative failure reason seen so far

        // Walk all occurrences — accept the first that sits at a word boundary,
        // doesn't cross a non-text-node marker, sits inside the context range,
        // and isn't already linked. "database" must skip "databases" and hit
        // the standalone "Database" next.
        while (true) {
            $found = $caseSensitive
                ? mb_strpos($fullText, $anchorText, $offset)
                : mb_stripos($fullText, $anchorText, $offset);

            if ($found === false) {
                // Exhausted occurrences — surface best failure tracked, or
                // anchor_not_found if every occurrence was rejected at the
                // word-boundary / null-byte gate (= no real anchor present).
                return $bestFailure ?? ['ok' => false, 'reason' => 'anchor_not_found'];
            }

            // Word-boundary + cross-null-byte gates mean "not a real
            // occurrence" → don't update bestFailure, just advance.
            if (! static::isAtWordBoundary($fullText, $found, $anchorLen)) {
                $offset = $found + $anchorLen;

                continue;
            }
            if (str_contains(mb_substr($fullText, $found, $anchorLen), "\0")) {
                $offset = $found + $anchorLen;

                continue;
            }

            // Context-range check — outside-range matches are a different
            // occurrence than the one the scan captured. Record as
            // context_mismatch and keep walking; a later occurrence might
            // pass or yield a stronger reason (crosses_existing_link).
            if ($contextRange !== null
                && ($found < $contextRange['start'] || $found + $anchorLen > $contextRange['end'])) {
                if ($bestFailure === null) {
                    $bestFailure = ['ok' => false, 'reason' => 'context_mismatch'];
                }
                $offset = $found + $anchorLen;

                continue;
            }

            $startMap = $nodeMap[$found];
            $endMap = $nodeMap[$found + $anchorLen - 1];

            if ($startMap['index'] === -1 || $endMap['index'] === -1) {
                // Anchor straddles a non-text boundary marker — same severity
                // as no occurrence.
                $offset = $found + $anchorLen;

                continue;
            }

            // Already-linked guard — REFUSE to mutate any text node that
            // already carries a link mark, regardless of href.
            //
            // History:
            // - Bug B (2026-05-08): partial-overlap split would tear an
            //   existing link apart ("Brauner-Zucker-Speck-Kekse"
            //   → "Brauner"=NEW + "-Zucker-Speck-Kekse"=OLD). Fixed for
            //   partial overlaps only — fully-covered matches still ran
            //   a "URL upgrade" that silently replaced the href.
            // - 2026-05-10: insert-parity audit + user feedback — silent
            //   URL-upgrade is the same bug-class as silent wrong-link
            //   unlink. USP is "kein silent overwrite". ANY existing link
            //   mark on an affected node = skip. Power-user wanting to
            //   remap an anchor uses URL-Changer to remove the old links
            //   first, then re-runs the rule. Two explicit steps, no
            //   surprise data loss.
            //
            // Bug 17 (2026-05-11): we now also capture the blocking href
            // so RelinkService can name it in an actionable
            // error message ("Anchor überlappt mit Link auf 'X'") rather
            // than a generic "would fail".
            //
            // When the anchor span crosses MULTIPLE link marks (the Bug 17
            // Repro A shape — anchor expanded to cover an existing
            // different-target link adjacent to a same-target one), prefer
            // the DIFFERENT-target href as the blocker: that's the actual
            // conflict the user has to resolve, not the same-target no-op
            // they'd implicitly clear via Step 1 anyway.
            $startIdx = $startMap['index'];
            $endIdx = $endMap['index'];
            $blockingHref = null;
            $sameTargetHref = null;

            for ($idx = $startIdx; $idx <= $endIdx; $idx++) {
                $child = $children[$idx];
                foreach ($child['marks'] ?? [] as $m) {
                    if (($m['type'] ?? '') !== 'link') {
                        continue;
                    }
                    $thisHref = $m['attrs']['href'] ?? '';

                    if ($thisHref !== $href) {
                        // Different target — strongest blocker, stop early.
                        $blockingHref = $thisHref;
                        break 2;
                    }
                    if ($sameTargetHref === null) {
                        $sameTargetHref = $thisHref;
                    }
                }
            }

            // Fall back to same-target only when no different-target mark
            // was seen across the span.
            if ($blockingHref === null) {
                $blockingHref = $sameTargetHref;
            }

            if ($blockingHref !== null) {
                $reason = ($blockingHref === $href) ? 'already_linked_to_target' : 'crosses_existing_link';
                $candidate = ['ok' => false, 'reason' => $reason, 'blocking_href' => $blockingHref];

                // crosses_existing_link is the strongest reason — it
                // overrides every weaker one. already_linked_to_target
                // beats context_mismatch / anchor_not_found, but loses to
                // a different-href crosses-link in case both occurrences
                // exist (we want to name the conflict, not the no-op).
                $currentReason = $bestFailure['reason'] ?? null;
                if ($currentReason === null
                    || $currentReason === 'anchor_not_found'
                    || $currentReason === 'context_mismatch'
                    || ($currentReason === 'already_linked_to_target' && $reason === 'crosses_existing_link')) {
                    $bestFailure = $candidate;
                }

                $offset = $found + $anchorLen;

                continue;
            }

            // All checks passed — valid match
            return [
                'ok' => true,
                'pos' => $found,
                'startNodeIndex' => $startIdx,
                'endNodeIndex' => $endIdx,
                'startOffset' => $startMap['offset'],
                'endOffset' => $endMap['offset'] + 1,
            ];
        }
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
