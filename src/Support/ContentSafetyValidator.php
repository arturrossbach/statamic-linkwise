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
 * Two operating modes:
 *
 *   ensureSafe(Entry):                         throw on ANY violation found
 *                                              (used for absolute correctness — debug + tests)
 *
 *   ensureNoNewViolations(Entry, Entry):       throw only when the after-state has
 *                                              MORE violations than the before-state
 *                                              (the production save path — Linkwise is
 *                                              only responsible for what IT changes,
 *                                              not for pre-existing user-content drift)
 *
 * The diff-mode is what SafeEntrySaver uses. A user editing an entry that
 * already had pre-existing corruption (from earlier dev iterations or
 * manual paste) can still complete legitimate operations — Linkwise only
 * blocks the save when WE made things worse.
 *
 * Invariants checked:
 *
 *   Markdown fields:
 *     - Anchor of every `[X](Y)` link contains no unmatched `[`
 *       (catches today's catastrophic nested-anchor corruption)
 *     - URL portion of every `[X](Y)` link contains no `](`
 *       (catches "anchor inside URL" corruption)
 *
 *   Bard fields (recursive ProseMirror tree):
 *     - Every link mark has a non-empty href
 *     - href contains no brackets or whitespace
 *     - Text nodes with link marks have non-empty visible text
 *
 *   Replicator fields: recurse into nested Bard fragments. Plain-string
 *   nested values are intentionally skipped (the retreat).
 */
class ContentSafetyValidator
{
    /**
     * Walk every relevant field of the entry and assert invariants.
     * Throws on first violation. Use this in tests + when you need
     * to fail fast regardless of pre-existing state.
     *
     * @throws ContentCorruptionException
     */
    public static function ensureSafe(Entry $entry): void
    {
        $violations = self::collectViolations($entry);
        if (empty($violations)) {
            return;
        }
        $first = $violations[0];
        throw new ContentCorruptionException(
            $entry->id() ?? '?',
            $first['field'],
            $first['reason'],
            $first['excerpt'],
        );
    }

    /**
     * Diff-based validation. Compares violation set in $before vs $after.
     * Throws ONLY when $after has more violations of any (field, reason)
     * tuple than $before — i.e., when this save introduced new corruption.
     *
     * Pre-existing corruption that is unchanged or partially repaired
     * does NOT block the save. Linkwise's job is to refuse to make things
     * worse, not to refuse to do legitimate work on imperfect data.
     *
     * @throws ContentCorruptionException
     */
    public static function ensureNoNewViolations(Entry $before, Entry $after): void
    {
        $beforeViolations = self::collectViolations($before);
        $afterViolations = self::collectViolations($after);

        $beforeCounts = self::countByKey($beforeViolations);
        $afterCounts = self::countByKey($afterViolations);

        foreach ($afterCounts as $key => $count) {
            $previous = $beforeCounts[$key] ?? 0;
            if ($count <= $previous) {
                continue; // unchanged or fewer than before — not our doing
            }
            // Find a representative violation matching this key for the error message.
            foreach ($afterViolations as $v) {
                if (self::keyOf($v) === $key) {
                    throw new ContentCorruptionException(
                        $after->id() ?? '?',
                        $v['field'],
                        'this save would introduce new corruption: '.$v['reason'],
                        $v['excerpt'],
                    );
                }
            }
        }
    }

    /**
     * Collect every violation in the entry as a structured array.
     * Empty array means clean.
     *
     * @return list<array{field: string, reason: string, excerpt: string}>
     */
    public static function collectViolations(Entry $entry): array
    {
        $violations = [];

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            // No blueprint = nothing to validate. Caller decides.
            return $violations;
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                self::collectFromBardTree((string) $handle, $value, $violations);
            } elseif ($field->type() === 'markdown' && is_string($value) && $value !== '') {
                self::collectFromMarkdown((string) $handle, $value, $violations);
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                self::collectFromReplicator((string) $handle, $value, $violations);
            }
        }

        return $violations;
    }

    /**
     * Append all markdown violations into $violations.
     *
     * @param  list<array{field: string, reason: string, excerpt: string}>  $violations
     */
    protected static function collectFromMarkdown(string $field, string $markdown, array &$violations): void
    {
        if (! preg_match_all('/\[([^\]]*)\]\(([^\)]+)\)/u', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach (array_keys($matches[0]) as $i) {
            [$anchorPortion, $anchorOffset] = $matches[1][$i];
            [$urlPortion, $urlOffset] = $matches[2][$i];

            // Pattern A: anchor contains an unmatched `[`. Today's
            // corruption — `[outer [inner](url)](url)` — has `outer [inner`
            // captured as the anchor of the FIRST regex match (since
            // [^\]]* is greedy-up-to-`]`, the inner `]` closes it).
            if (str_contains($anchorPortion, '[')) {
                $violations[] = [
                    'field' => $field,
                    'reason' => 'markdown link anchor contains an unmatched `[` — likely a nested-link corruption',
                    'excerpt' => self::excerpt($markdown, (int) $anchorOffset),
                ];
            }

            // Pattern B: URL portion contains `](`. A markdown link sat
            // inside another link's URL — the "anchor matched inside URL"
            // corruption.
            if (str_contains($urlPortion, '](')) {
                $violations[] = [
                    'field' => $field,
                    'reason' => 'URL portion of a markdown link contains another `](` — link nested inside URL',
                    'excerpt' => self::excerpt($markdown, (int) $urlOffset),
                ];
            }
        }
    }

    /**
     * Walk a Bard ProseMirror tree, append every violation found.
     *
     * @param  array  $content  ProseMirror node array
     * @param  list<array{field: string, reason: string, excerpt: string}>  $violations
     */
    protected static function collectFromBardTree(string $field, array $content, array &$violations): void
    {
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }
            self::collectFromBardNode($field, $node, $violations);
        }
    }

    /**
     * @param  list<array{field: string, reason: string, excerpt: string}>  $violations
     */
    protected static function collectFromBardNode(string $field, array $node, array &$violations): void
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (! is_array($mark) || ($mark['type'] ?? '') !== 'link') {
                continue;
            }

            $href = (string) ($mark['attrs']['href'] ?? '');

            if ($href === '') {
                $violations[] = [
                    'field' => $field,
                    'reason' => 'Bard link mark has empty href',
                    'excerpt' => '',
                ];
            } elseif (preg_match('/[\[\]\s]/', $href)) {
                $violations[] = [
                    'field' => $field,
                    'reason' => 'Bard link mark href contains brackets or whitespace (likely markdown syntax leaked into URL)',
                    'excerpt' => $href,
                ];
            }
        }

        // Text node with link mark must have non-empty visible text.
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
                $violations[] = [
                    'field' => $field,
                    'reason' => 'Bard text node has link mark but empty visible text',
                    'excerpt' => '',
                ];
            }
        }

        // Recurse into children.
        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    self::collectFromBardNode($field, $child, $violations);
                }
            }
        }
    }

    /**
     * Replicator: recurse into nested Bard fragments and other Replicator
     * sets. Plain-string nested values intentionally skipped (the retreat:
     * we don't write there, so existing content there is the user's
     * responsibility, not Linkwise's prevention scope).
     *
     * @param  array  $sets
     * @param  list<array{field: string, reason: string, excerpt: string}>  $violations
     */
    protected static function collectFromReplicator(string $field, array $sets, array &$violations): void
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
                    self::collectFromBardTree($field.'/'.$key, $value, $violations);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    self::collectFromReplicator($field.'/'.$key, $value, $violations);
                }
            }
        }
    }

    /**
     * Group violations by (field, reason) tuple and return counts.
     *
     * @param  list<array{field: string, reason: string, excerpt: string}>  $violations
     * @return array<string, int>
     */
    protected static function countByKey(array $violations): array
    {
        $counts = [];
        foreach ($violations as $v) {
            $key = self::keyOf($v);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Stable key for grouping. Excerpt is intentionally NOT part of the
     * key — surrounding text can shift even when the violation itself is
     * unchanged, so we identify violations by (field, reason) only.
     *
     * @param  array{field: string, reason: string, excerpt: string}  $v
     */
    protected static function keyOf(array $v): string
    {
        return $v['field'].'::'.$v['reason'];
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
