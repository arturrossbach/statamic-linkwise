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
     * Identity is per-MARK, keyed by (offset, length) in the field's flat
     * text. For each before-mark, after must either:
     *   (a) contain a mark at the same offset whose length is the same or
     *       larger (preserved or extended); OR
     *   (b) contain NO mark with the same href overlapping the before-
     *       mark's range (deliberate full removal).
     * Anything else — an after-mark with the same href whose range partly
     * overlaps the before-mark but starts or ends elsewhere — is the
     * Bug B partial-overlap signature and throws.
     *
     * Why per-mark and not per-href-total: a single entry can hold multiple
     * link marks pointing at the same href (e.g. "Erdnuss-Soba-Nudeln"
     * linked twice in different paragraphs). Removing one of them legit-
     * imately drops the total char count for that href, but is NOT
     * corruption (Bug 2026-05-11: per-href-total fired false-positive on
     * legit "unlink one of three" operations, blocking the user).
     *
     * Cases this allows (legitimate):
     *   - LinkInsert (no before-mark, new after-mark)
     *   - DetailUnlink, full or per-mark (before-marks fully absent in after)
     *   - URL-Changer full replace (old href: all marks removed; new href: new marks)
     *   - Auto-Link extension (existing mark preserved, additional marks appear)
     *   - Auto-Link grow-in-place (same offset, larger length)
     *
     * Case this refuses (corruption):
     *   - Bug B partial overlap (mark shrunk, replacement mark at offset within original span)
     *
     * @throws ContentCorruptionException
     */
    public static function ensureLinkCoveragePreserved(Entry $before, Entry $after, ?array $relinkContext = null): void
    {
        $beforeCoverage = self::collectLinkCoverage($before);
        $afterCoverage = self::collectLinkCoverage($after);

        // Re-link bypass — explicit operation context from RelinkService.
        //
        // The principle "any same-href partial overlap = corruption" is
        // correct for the Bug B silent-split threat the validator was
        // built for. An intentional re-anchor (same href, anchor moves
        // within its original span) trips the same shape but isn't
        // corruption — the caller declares the substitution explicitly,
        // so the overlap is no longer silent.
        //
        // The bypass skips the partial-overlap check for ONE bm declared
        // by (field, href, occurrence_index). All other bms — at the same
        // href in other positions, at other hrefs, in other fields —
        // still get the full check. A Bug B variant introduced in a
        // different range will still throw.
        //
        // The occurrence_index ordering matches collectLinkCoverage's
        // traversal order (per-href, tree-traversal). RelinkService passes
        // the same per-field index UrlReplacer used to find the link.
        $skipField = $relinkContext['field'] ?? null;
        $skipHref = $relinkContext['href'] ?? null;
        $skipIndex = $relinkContext['occurrence_index'] ?? null;

        foreach ($beforeCoverage as $field => $hrefMap) {
            foreach ($hrefMap as $href => $beforeMarks) {
                $afterMarks = $afterCoverage[$field][$href] ?? [];

                foreach ($beforeMarks as $beforeIdx => $bm) {
                    if ((string) $field === (string) $skipField
                        && (string) $href === (string) $skipHref
                        && $beforeIdx === $skipIndex) {
                        continue;
                    }
                    $bmEnd = $bm['offset'] + $bm['length'];

                    // (a) Preserved or extended at same start.
                    $matched = false;
                    foreach ($afterMarks as $am) {
                        if ($am['offset'] === $bm['offset'] && $am['length'] >= $bm['length']) {
                            $matched = true;
                            break;
                        }
                    }
                    if ($matched) continue;

                    // (b) Cleanly removed — no overlap from any after-mark.
                    $overlap = null;
                    foreach ($afterMarks as $am) {
                        $amEnd = $am['offset'] + $am['length'];
                        if ($am['offset'] < $bmEnd && $amEnd > $bm['offset']) {
                            $overlap = $am;
                            break;
                        }
                    }
                    if ($overlap === null) continue;

                    // Anything else = partial destruction.
                    throw new ContentCorruptionException(
                        $after->id() ?? '?',
                        $field,
                        sprintf(
                            'this save would shorten an existing link without removing it: '
                            .'href "%s" had a %d-char mark at offset %d, after has a %d-char mark at offset %d '
                            .'overlapping the original (partial-overlap split detected)',
                            $href,
                            $bm['length'],
                            $bm['offset'],
                            $overlap['length'],
                            $overlap['offset'],
                        ),
                        sprintf(
                            'href=%s before=offset:%d,length:%d after=offset:%d,length:%d',
                            $href,
                            $bm['offset'],
                            $bm['length'],
                            $overlap['offset'],
                            $overlap['length'],
                        ),
                    );
                }
            }
        }
    }

    /**
     * Reject saves that introduce NEW adjacent same-mark-set text-node
     * pairs (the "fragmented Bard" anti-pattern).
     *
     * Background — Bug 16 root cause (2026-05-11):
     * Linkwise's display path ({@see TextExtractor::extractFromEntry()})
     * merges adjacent text nodes that share a mark-set into one logical
     * span; the write paths (`BardLinkInserter::linkAcrossNodes`,
     * post-unlink mark-strip) leave them fragmented. Statamic Bard does
     * NOT normalize on save (verified via save-roundtrip with no changes:
     * file bytes identical, fragments persist). The two divergent views
     * cause silent NO-OPs in URL-Changer apply, which silently fails
     * the Re-Link-after-anchor-edit flow.
     *
     * The root fix is the normalization invariant in
     * {@see BardWalker::normalizeChildren()}, called from
     * `SafeEntrySaver::save` as a final pass. This validator method is
     * the fail-closed safety net: if a future code path bypasses the
     * normalizer (direct entry mutation in tests, third-party tooling),
     * a save that INTRODUCES new adjacent same-mark-pairs is refused.
     *
     * Diff-mode like {@see ensureNoNewViolations()}: existing fragmented
     * data does NOT block the save. We are responsible for what WE
     * introduce, not for what was there before — same retreat used by
     * the rest of this class. The migration command
     * `linkwise:normalize-bard` is the cleanup channel for pre-existing
     * fragments, not this validator.
     *
     * What counts as an "adjacent same-mark pair":
     *   - Two text nodes that are direct siblings (no non-text node in
     *     between) within the same children-array (paragraph content,
     *     listItem content, set body Bard fragment, etc.).
     *   - Both nodes carry the same mark-set, compared order-agnostically
     *     by (type, attrs). This includes the no-marks case (plain text +
     *     plain text), the Bug 16 case (link+link to same href), and
     *     mixed marks (bold+link with matching href on both sides).
     *
     * Scope: walks Bard fields and replicator-nested Bard fragments.
     * Markdown fields are atomic `[anchor](url)` syntax — no fragment
     * risk, no walk needed.
     *
     * @throws ContentCorruptionException  When the after-state has more
     *                                     adjacent-same-mark pairs in any
     *                                     field than the before-state.
     */
    public static function ensureNoNewAdjacentSameMarks(Entry $before, Entry $after): void
    {
        $beforeCounts = self::countAdjacentSameMarkPairsByField($before);
        $afterCounts = self::countAdjacentSameMarkPairsByField($after);

        foreach ($afterCounts as $field => $afterCount) {
            $beforeCount = $beforeCounts[$field] ?? 0;
            if ($afterCount <= $beforeCount) {
                continue; // unchanged or fewer — not our doing
            }

            throw new ContentCorruptionException(
                $after->id() ?? '?',
                $field,
                sprintf(
                    'this save would introduce %d new fragmented-link pair(s): adjacent text nodes '
                    .'with identical mark-set (the Bug 16 fragmentation pattern — write path produced '
                    .'two adjacent marks where one merged mark belongs; '
                    .'normalize via BardWalker::normalizeChildren() before save)',
                    $afterCount - $beforeCount,
                ),
                sprintf('field=%s before_pairs=%d after_pairs=%d', $field, $beforeCount, $afterCount),
            );
        }
    }

    /**
     * Count adjacent-same-mark-set text-node pairs per field.
     *
     * Walks the same set of fields as the other coverage/violation walkers
     * (Bard, Replicator-nested-Bard). Markdown is skipped — atomic syntax,
     * no fragment risk.
     *
     * @return array<string, int>  Field handle → pair count.
     */
    protected static function countAdjacentSameMarkPairsByField(Entry $entry): array
    {
        $counts = [];

        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return $counts;
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);
            $key = (string) $handle;

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                $counts[$key] = self::countAdjacentSameMarkPairs($value);
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $counts[$key] = self::countAdjacentSameMarkPairsInReplicator($value);
            }
        }

        return $counts;
    }

    /**
     * Recursively count adjacent-same-mark text-node pairs in a Bard tree.
     * Counts pairs at every nesting level (paragraph, list-item content,
     * set body, etc.) and across runs (3 same-mark siblings = 2 pairs).
     */
    protected static function countAdjacentSameMarkPairs(array $children): int
    {
        $pairs = 0;
        $prevSig = null;

        foreach ($children as $child) {
            if (! is_array($child)) {
                $prevSig = null;
                continue;
            }

            $type = $child['type'] ?? '';

            // codeBlock content is opaque — fragments inside (unlikely
            // anyway) are not our concern. Consistent with the other
            // walkers in this file.
            if (! in_array($type, ['codeBlock', 'code_block'], true)) {
                if (isset($child['content']) && is_array($child['content'])) {
                    $pairs += self::countAdjacentSameMarkPairs($child['content']);
                }
                if ($type === 'set' && isset($child['attrs']['values']) && is_array($child['attrs']['values'])) {
                    foreach ($child['attrs']['values'] as $key => $val) {
                        if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) continue;
                        if (! is_array($val) || empty($val)) continue;
                        if (ProseMirrorTypes::looksLikeBardContent($val)) {
                            $pairs += self::countAdjacentSameMarkPairs($val);
                        }
                    }
                }
            }

            if ($type !== 'text' || ! isset($child['text'])) {
                $prevSig = null;
                continue;
            }

            $sig = self::markSetSignature($child['marks'] ?? []);
            if ($prevSig !== null && $sig === $prevSig) {
                $pairs++;
            }
            $prevSig = $sig;
        }

        return $pairs;
    }

    /**
     * Walk a Replicator value tree, summing adjacent-same-mark pairs
     * across nested Bard fragments. Matches the traversal in
     * {@see collectMarksFromReplicator()}.
     */
    protected static function countAdjacentSameMarkPairsInReplicator(array $sets): int
    {
        $pairs = 0;
        foreach ($sets as $set) {
            if (! is_array($set)) continue;
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $pairs += self::countAdjacentSameMarkPairs($value);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    $pairs += self::countAdjacentSameMarkPairsInReplicator($value);
                }
            }
        }
        return $pairs;
    }

    /**
     * Canonical signature for a mark-set, order-agnostic. Two text nodes
     * with the same signature are considered to carry the same marks for
     * the purpose of the fragmentation check. Mirrors
     * {@see BardWalker::sameMarkSet()} to keep the invariant comparable
     * between the writer (normalize) and the validator (this).
     */
    protected static function markSetSignature(array $marks): string
    {
        $signatures = [];
        foreach ($marks as $m) {
            if (! is_array($m)) {
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
        return implode('||', $signatures);
    }

    /**
     * Per-field, per-href list of link marks with their flat-text offsets.
     *
     * Each mark is `{offset: int, length: int}` (length = mb_strlen of the
     * anchor text). The offset is character-position in the field's flat
     * text as produced by {@see TextExtractor::extractTextAndLinksFromBard()}
     * — comparable between before/after of the same field because saves
     * are per-field (no cross-field offset shift to worry about).
     *
     * @return array<string, array<string, list<array{offset: int, length: int}>>>
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
                $coverage[$key] = self::marksByHrefFromBard($value);
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                $marks = [];
                self::collectMarksFromReplicator($value, $marks);
                $coverage[$key] = $marks;
            } elseif ($field->type() === 'markdown' && is_string($value) && $value !== '') {
                $coverage[$key] = self::marksByHrefFromMarkdown($value);
            }
        }

        return $coverage;
    }

    /**
     * Group every link mark (any href shape) from a Bard tree by href,
     * each with its character offset in a flat walk of the tree. The
     * offsets are local to this Bard tree (no cross-field shift) — fine
     * for the safety check which compares before/after of one save.
     *
     * Walks more permissively than {@see TextExtractor::extractTextAndLinksFromBard()},
     * which filters for well-formed internal/external href prefixes. The
     * safety gate must catch corruption involving ANY href format (Bug B's
     * regression test deliberately uses `statamic::cookies` — no `entry::`
     * segment — to lock the gate against future prefix changes).
     *
     * @return array<string, list<array{offset: int, length: int}>>
     */
    protected static function marksByHrefFromBard(array $content): array
    {
        $state = ['offset' => 0, 'out' => []];
        self::walkBardMarks($content, $state);

        return $state['out'];
    }

    /**
     * Recursive walker mirroring {@see TextExtractor::extractTextAndLinksFromBard()}
     * text-accumulation rules (top-level "\n" joins, block-vs-inline
     * separators, codeBlock skip) so offsets stay consistent with the
     * rest of the codebase. Captures every link mark regardless of href
     * shape.
     */
    protected static function walkBardMarks(array $content, array &$state, bool $topLevel = true, string $separator = ''): void
    {
        $first = true;
        foreach ($content as $node) {
            if (! is_array($node)) continue;
            $type = $node['type'] ?? '';

            if (in_array($type, ['codeBlock', 'code_block'], true)) {
                continue;
            }

            // Determine pre-node separator emission. Top-level uses "\n"
            // between non-empty nodes; block-level containers use the
            // supplied $separator. To know "non-empty", peek-walk the
            // node into a child state first.
            $childState = ['offset' => $state['offset'], 'out' => []];
            self::walkBardNodeMarks($node, $childState);
            $producedText = $childState['offset'] > $state['offset'];
            $producedMarks = ! empty($childState['out']);

            if (! $producedText && ! $producedMarks) {
                continue;
            }

            if (! $first) {
                if ($topLevel) {
                    $state['offset'] += 1; // "\n"
                } elseif ($separator !== '') {
                    $state['offset'] += mb_strlen($separator);
                }
            }
            $first = false;

            // Re-walk with the correct base offset.
            self::walkBardNodeMarks($node, $state);
        }
    }

    protected static function walkBardNodeMarks(array $node, array &$state): void
    {
        $type = $node['type'] ?? '';

        if (in_array($type, ['codeBlock', 'code_block'], true)) {
            return;
        }

        if ($type === 'set') {
            $values = $node['attrs']['values'] ?? null;
            if (! is_array($values)) return;
            $first = true;
            foreach ($values as $key => $val) {
                if ($key === 'type' || $key === 'enabled' || $key === 'id') continue;
                if (is_string($val)) {
                    if (! InsertableContentFilter::isContent($val, (string) $key)) continue;
                    $trimmed = trim($val);
                    if ($trimmed === '') continue;
                    if (! $first) $state['offset'] += 1; // " " separator
                    $state['offset'] += mb_strlen($trimmed);
                    $first = false;
                } elseif (is_array($val) && ! empty($val) && isset($val[0]['type'])) {
                    if (! $first) $state['offset'] += 1;
                    self::walkBardMarks($val, $state, topLevel: true);
                    $first = false;
                }
            }
            return;
        }

        // Text node — record its link marks at the current offset, then
        // advance offset past the text.
        if (isset($node['text'])) {
            $text = (string) $node['text'];
            if ($text === '') return;
            foreach ($node['marks'] ?? [] as $mark) {
                if (! is_array($mark) || ($mark['type'] ?? '') !== 'link') continue;
                $href = (string) ($mark['attrs']['href'] ?? '');
                if ($href === '') continue;
                $state['out'][$href][] = [
                    'offset' => $state['offset'],
                    'length' => mb_strlen($text),
                ];
            }
            $state['offset'] += mb_strlen($text);
            return;
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $blockTypes = ['table', 'tableRow', 'tableCell', 'tableHeader', 'bulletList', 'orderedList', 'listItem', 'blockquote'];
            $sep = in_array($type, $blockTypes, true) ? ' ' : '';
            self::walkBardMarks($node['content'], $state, topLevel: false, separator: $sep);
        }
    }

    /**
     * Walk a Replicator value tree, merging marks from nested Bard sub-
     * trees into one per-href map. Replicator entries are independent
     * value containers — offsets reset per nested Bard. The safety check
     * compares before vs after of the SAME save, so for the
     * partial-overlap detection it's enough that the offset numbering is
     * deterministic within each nested Bard (which it is: walker order).
     *
     * @param  array<string, list<array{offset: int, length: int}>>  $out
     */
    protected static function collectMarksFromReplicator(array $sets, array &$out): void
    {
        foreach ($sets as $set) {
            if (! is_array($set)) continue;
            foreach ($set as $key => $value) {
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true) || ! is_array($value) || empty($value)) {
                    continue;
                }
                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    foreach (self::marksByHrefFromBard($value) as $href => $marks) {
                        foreach ($marks as $m) {
                            $out[$href][] = $m;
                        }
                    }
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    self::collectMarksFromReplicator($value, $out);
                }
            }
        }
    }

    /**
     * Parse `[anchor](href)` markdown links, return one record per match
     * with the anchor's offset in the link-stripped plain text (matching
     * {@see TextExtractor::extractTextAndLinksFromMarkdown()} so offsets
     * align with snippets/extractAtOffset).
     *
     * @return array<string, list<array{offset: int, length: int}>>
     */
    protected static function marksByHrefFromMarkdown(string $markdown): array
    {
        $bundle = TextExtractor::extractTextAndLinksFromMarkdown($markdown);
        $out = [];
        foreach ($bundle['internal_links'] as $link) {
            $out[$link['href']][] = [
                'offset' => $link['offset'],
                'length' => mb_strlen($link['anchor_text']),
            ];
        }
        foreach ($bundle['external_links'] as $link) {
            $out[$link['url']][] = [
                'offset' => $link['offset'],
                'length' => mb_strlen($link['anchor_text']),
            ];
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
