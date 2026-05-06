<?php

namespace Arturrossbach\Linkwise\Support;

use Arturrossbach\Linkwise\Exceptions\ContentCorruptionException;
use Statamic\Entries\Entry;

/**
 * Last line of defense before content reaches disk.
 *
 * Linkwise's Achilles heel: a bug in the insertion path could produce
 * visibly corrupt content (`[[anchor]](url)](url)`, broken Bard trees,
 * markdown syntax in plaintext fields). The retreat + skipRanges fixes
 * we shipped today close the bug classes we know about — this validator
 * closes the bug classes we don't yet know about.
 *
 * Hooked into SafeEntrySaver::save: BEFORE $entry->save() is called, we
 * walk the modified content and assert invariants. Any violation throws
 * ContentCorruptionException; the entry is never saved. The user sees a
 * clear error toast, support gets a logged stack trace pointing at the
 * upstream code path that produced the bad output.
 *
 * Invariants checked:
 *
 *   Markdown fields:
 *     - No `]](` substring (nested-anchor closing — only appears in corrupt
 *       output where one markdown link contains another in its anchor or URL)
 *     - No `(...](...) ` pattern inside a link's URL portion (a `](` inside
 *       a URL means a markdown link was inserted into another link's URL)
 *
 *   Bard fields (recursive ProseMirror tree):
 *     - Every link mark has a non-empty href
 *     - href does not contain unescaped `[`, `]`, or whitespace
 *       (those would mean the URL itself is malformed markdown)
 *     - Text nodes with link marks have non-empty visible text
 *
 *   Replicator fields:
 *     - Recurse into nested Bard fragments and apply the Bard rules
 *     - Plain-string nested values: skipped (the retreat means we don't
 *       write there; if user-pasted content has markdown syntax that's
 *       their content and not Linkwise's responsibility to police)
 *
 * The validator is intentionally CONSERVATIVE in what it flags. Stylistic
 * bracket use in plain prose (`[See: example]`, `(parens)`) is fine.
 * Only patterns that cannot occur in well-formed user content are rejected.
 */
class ContentSafetyValidator
{
    /**
     * Walk every relevant field of the entry and assert invariants.
     * Throws on first violation — the entry is corrupt and must not save.
     *
     * @throws ContentCorruptionException
     */
    public static function ensureSafe(Entry $entry): void
    {
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            // No blueprint = nothing to validate against. Save proceeds.
            // (SafeEntrySaver will hit the same blueprint failure if it
            // matters; we don't want to block save on transient blueprint
            // errors.)
            return;
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                self::validateBardTree($entry->id() ?? '?', (string) $handle, $value);
            } elseif ($field->type() === 'markdown' && is_string($value) && $value !== '') {
                self::validateMarkdown($entry->id() ?? '?', (string) $handle, $value);
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                self::validateReplicator($entry->id() ?? '?', (string) $handle, $value);
            }
        }
    }

    /**
     * Markdown content must not contain markdown-link patterns that are
     * themselves inside another markdown link's anchor or URL portion.
     */
    protected static function validateMarkdown(string $entryId, string $field, string $markdown): void
    {
        // Walk every `[ANCHOR](URL)` match and inspect both portions.
        // The non-greedy regex matches the inner-most link first when
        // anchors are nested — exactly what we want, because the inner
        // link's anchor text will contain telltale `[` characters that
        // came from the unbalanced outer `[`.
        if (preg_match_all('/\[([^\]]*)\]\(([^\)]+)\)/u', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            foreach (array_keys($matches[0]) as $i) {
                [$anchorPortion, $anchorOffset] = $matches[1][$i];
                [$urlPortion, $urlOffset] = $matches[2][$i];

                // Pattern A: anchor contains an unmatched `[`. Today's
                // corruption — `[outer [inner](url)](url)` — has `outer [inner`
                // captured as the anchor of the FIRST regex match (since
                // [^\]]* is greedy-up-to-`]`, the inner `]` closes it).
                // A `[` in the anchor means there's an unclosed open
                // bracket — the markdown is malformed.
                if (str_contains($anchorPortion, '[')) {
                    throw new ContentCorruptionException(
                        $entryId,
                        $field,
                        'markdown link anchor contains an unmatched `[` — likely a nested-link corruption',
                        self::excerpt($markdown, (int) $anchorOffset),
                    );
                }

                // Pattern B: URL portion contains `](`. That means a
                // markdown link sat inside another link's URL — the
                // "anchor matched inside URL" corruption from today.
                if (str_contains($urlPortion, '](')) {
                    throw new ContentCorruptionException(
                        $entryId,
                        $field,
                        'URL portion of a markdown link contains another `](` — link nested inside URL',
                        self::excerpt($markdown, (int) $urlOffset),
                    );
                }
            }
        }
    }

    /**
     * Walk a Bard ProseMirror tree and validate link marks.
     *
     * @param  array  $content  ProseMirror node array
     */
    protected static function validateBardTree(string $entryId, string $field, array $content): void
    {
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }
            self::validateBardNode($entryId, $field, $node);
        }
    }

    protected static function validateBardNode(string $entryId, string $field, array $node): void
    {
        // Inspect this node's marks (if any).
        foreach ($node['marks'] ?? [] as $mark) {
            if (! is_array($mark) || ($mark['type'] ?? '') !== 'link') {
                continue;
            }

            $href = (string) ($mark['attrs']['href'] ?? '');

            // Empty href = broken link mark. ProseMirror allows it
            // technically but it produces `<a href="">` in HTML which is
            // semantically wrong (an anchor pointing nowhere).
            if ($href === '') {
                throw new ContentCorruptionException(
                    $entryId,
                    $field,
                    'Bard link mark has empty href',
                );
            }

            // URLs with brackets/whitespace inside are corrupt — markdown
            // syntax leaked into the href. Real URLs are %-encoded, so
            // bare `[`, `]`, or whitespace in href is a red flag.
            if (preg_match('/[\[\]\s]/', $href)) {
                throw new ContentCorruptionException(
                    $entryId,
                    $field,
                    'Bard link mark href contains brackets or whitespace (likely markdown syntax leaked into URL)',
                    $href,
                );
            }
        }

        // A text node carrying a link mark must have non-empty text —
        // an empty anchor with a link mark is invisible to the user but
        // technically a link, breaking screen readers + copy-paste.
        $isText = ($node['type'] ?? '') === 'text';
        $hasLinkMark = false;
        foreach ($node['marks'] ?? [] as $m) {
            if (($m['type'] ?? '') === 'link') {
                $hasLinkMark = true;
                break;
            }
        }
        if ($isText && $hasLinkMark) {
            $text = (string) ($node['text'] ?? '');
            if ($text === '') {
                throw new ContentCorruptionException(
                    $entryId,
                    $field,
                    'Bard text node has link mark but empty visible text',
                );
            }
        }

        // Recurse into children (paragraphs, headings, list items, etc.).
        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    self::validateBardNode($entryId, $field, $child);
                }
            }
        }
    }

    /**
     * Replicator: walk sets, recurse into nested Bard fragments, and skip
     * plain-string nested values (the retreat: we don't write there, so
     * whatever's there is the user's responsibility).
     *
     * @param  array  $sets  Replicator set array
     */
    protected static function validateReplicator(string $entryId, string $field, array $sets): void
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
                    self::validateBardTree($entryId, $field.'/'.$key, $value);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    self::validateReplicator($entryId, $field.'/'.$key, $value);
                }
            }
        }
    }

    /**
     * Snip ~80 chars around the offending position for diagnostics.
     */
    protected static function excerpt(string $text, int $offset, int $window = 80): string
    {
        $start = max(0, $offset - intdiv($window, 2));
        $excerpt = mb_substr($text, $start, $window);

        return ($start > 0 ? '…' : '').$excerpt.($start + $window < mb_strlen($text) ? '…' : '');
    }
}
