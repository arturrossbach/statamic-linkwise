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
     * Runtime gate against partial destruction of an existing link.
     *
     * Real bug 2026-05-08 (Bug B): a single Bard text node "Brauner-Zucker-
     * Speck-Kekse" linked to entry X. An Outbound suggestion proposed
     * anchor "Brauner" → entry Y. The single-walker split the linked
     * text node into "Brauner"(Y) + "-Zucker-Speck-Kekse"(X). The original
     * link was torn in half, the user lost trust in revert.
     *
     * The walker fix (BardLinkInserter::findAndLinkInChildren — refuse
     * partial-overlap matches) closes the known path. THIS check closes
     * any future path that gets the same effect by a different route:
     * for each href in $before with N>0 linked chars, $after must have
     * either >=N chars (preserved or extended) OR 0 chars (deliberate
     * removal). 0 < after < before is partial destruction → throw.
     *
     * Cases this allows (legitimate):
     *   - LinkInsert (0 → N for new href)
     *   - DetailUnlink (N → 0 for removed href)
     *   - URL-Changer full replace (N → 0 for old href, 0 → N for new)
     *   - Auto-Link extension (N → N+M when an additional occurrence got linked)
     *
     * Case this refuses (corruption):
     *   - Bug B partial overlap (26 → 18 for the original href)
     *   - Any future code path that destructures linked text into
     *     adjacent fragments with different marks
     *
     * @throws ContentCorruptionException
     */
    public static function ensureLinkCoveragePreserved(Entry $before, Entry $after): void
    {
        $beforeCoverage = self::collectLinkCoverage($before);
        $afterCoverage = self::collectLinkCoverage($after);

        foreach ($beforeCoverage as $field => $hrefMap) {
            foreach ($hrefMap as $href => $beforeChars) {
                if ($beforeChars <= 0) continue;
                $afterChars = $afterCoverage[$field][$href] ?? 0;

                // 0 < after < before → partial destruction.
                if ($afterChars > 0 && $afterChars < $beforeChars) {
                    throw new ContentCorruptionException(
                        $after->id() ?? '?',
                        $field,
                        sprintf(
                            'this save would shorten an existing link without removing it: '
                            .'href "%s" had %d linked chars before, %d after (partial-overlap split detected)',
                            $href,
                            $beforeChars,
                            $afterChars,
                        ),
                        sprintf('href=%s before=%d after=%d', $href, $beforeChars, $afterChars),
                    );
                }
            }
        }
    }

    /**
     * Per-field, per-href total character count of linked text.
     *
     * @return array<string, array<string, int>>  [fieldHandle => [href => totalChars]]
     */
    protected static function collectLinkCoverage(Entry $entry): array
    {
        $coverage = [];

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return $coverage;
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);
            $key = (string) $handle;

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                $hrefs = [];
                self::sumLinkCharsInBard($value, $hrefs);
                $coverage[$key] = $hrefs;
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $hrefs = [];
                self::sumLinkCharsInReplicator($value, $hrefs);
                $coverage[$key] = $hrefs;
            } elseif ($field->type() === 'markdown' && is_string($value) && $value !== '') {
                $coverage[$key] = self::sumLinkCharsInMarkdown($value);
            }
        }

        return $coverage;
    }

    /**
     * Walk a Bard tree, accumulate href → linked-chars sums.
     *
     * @param  array  $content  ProseMirror node array
     * @param  array<string, int>  $out
     */
    protected static function sumLinkCharsInBard(array $content, array &$out): void
    {
        foreach ($content as $node) {
            if (! is_array($node)) continue;

            if (($node['type'] ?? '') === 'text') {
                $text = (string) ($node['text'] ?? '');
                if ($text !== '') {
                    foreach ($node['marks'] ?? [] as $mark) {
                        if (! is_array($mark) || ($mark['type'] ?? '') !== 'link') continue;
                        $href = (string) ($mark['attrs']['href'] ?? '');
                        if ($href === '') continue;
                        $out[$href] = ($out[$href] ?? 0) + mb_strlen($text);
                    }
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                self::sumLinkCharsInBard($node['content'], $out);
            }
        }
    }

    /**
     * Walk a Replicator value, accumulate per-href linked chars from
     * nested Bard fragments.
     *
     * @param  array<string, int>  $out
     */
    protected static function sumLinkCharsInReplicator(array $sets, array &$out): void
    {
        foreach ($sets as $set) {
            if (! is_array($set)) continue;
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    self::sumLinkCharsInBard($value, $out);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    self::sumLinkCharsInReplicator($value, $out);
                }
            }
        }
    }

    /**
     * Parse [anchor](href) markdown links, sum mb_strlen(anchor) per href.
     *
     * @return array<string, int>
     */
    protected static function sumLinkCharsInMarkdown(string $markdown): array
    {
        $out = [];
        if (! preg_match_all('/\[([^\]]*)\]\(([^\)]+)\)/u', $markdown, $matches)) {
            return $out;
        }
        foreach ($matches[1] as $i => $anchor) {
            $href = $matches[2][$i];
            if ($href === '') continue;
            $out[$href] = ($out[$href] ?? 0) + mb_strlen((string) $anchor);
        }

        return $out;
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
