<?php

namespace Arturrossbach\Linkwise\Support\Replicator;

use Arturrossbach\Linkwise\Support\Bard\AnchorPositionFinder;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\ProseMirrorTypes;
use Arturrossbach\Linkwise\Support\UrlHelper;

/**
 * Replicator routing for the link-inserter family — extracted from
 * {@see \Arturrossbach\Linkwise\Support\BardLinkInserter} as part of
 * the REV-OB-03 god-class split (Sprint 4 Part 3 Phase B).
 *
 * A Replicator field is a list of "sets" (associative arrays). Inside
 * each set, individual keys may carry:
 *   - a nested Bard fragment (typed PM nodes) — delegated to BardLinkInserter
 *   - another Replicator value (list of sets) — recursed in this class
 *   - a plain string / scalar — intentionally skipped (plain-text fields
 *     in a set have no way to safely receive `[anchor](url)` markdown
 *     without rendering literal syntax; see processReplicatorWithHref).
 *
 * Routing direction: Replicator → Bard (never the other way). The Bard
 * primitives stay in BardLinkInserter, which is the canonical home for
 * ProseMirror-tree mutation. This class holds *only* the Replicator
 * navigation skeleton; every leaf operation re-enters BardLinkInserter
 * via its public API.
 *
 * Public BardLinkInserter methods retained as delegation shims preserve
 * the existing call sites in RelinkService, AuditCommand, and the
 * MutatorAndInsertParity / InsertContextDisambiguation test suites.
 */
class ReplicatorLinkRouter
{
    /**
     * Detect "this array looks like a replicator value" — a list whose
     * first element is itself an associative array with type+id keys.
     *
     * Symmetric with {@see ProseMirrorTypes::looksLikeBardContent} so
     * the two field-shape probes live side-by-side in the type module
     * eventually. For now both probes are private to their concern;
     * Bard-content detection moved into ProseMirrorTypes earlier
     * (Sprint 4 Part 2), this one follows the same pattern in-place.
     */
    private static function looksLikeReplicatorContent(array $value): bool
    {
        $first = reset($value);

        return is_array($first) && isset($first['type']) && isset($first['id']);
    }

    /** Count link marks pointing at $href anywhere in a replicator structure. */
    public static function countLinksToInReplicator(array $sets, string $href): int
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
                    $count += BardLinkInserter::countLinksTo($value, $href);
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $count += static::countLinksToInReplicator($value, $href);
                }
            }
        }

        return $count;
    }

    /** Multi-insert across nested Bard fragments inside a replicator. */
    public static function processAllInReplicator(array $sets, string $anchorText, string $href, bool $caseSensitive = false): ?array
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
                    $result = BardLinkInserter::insertAllLinksWithHref($value, $anchorText, $href, $caseSensitive);
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

    /**
     * Replicator dry-run mirroring {@see processReplicatorWithHref}.
     *
     * @return array{ok: bool, content?: array, reason?: string, blocking_href?: string}
     */
    public static function canInsertLinkIntoReplicator(array $sets, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): array
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
                    $result = BardLinkInserter::canInsertLinkIntoBardContent($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $result = static::canInsertLinkIntoReplicator($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);
                } else {
                    continue;
                }

                if ($result['ok'] ?? false) {
                    return $result;
                }
                $bestFailure = AnchorPositionFinder::pickWorseFailure($bestFailure, $result);
            }
        }

        return $bestFailure ?? ['ok' => false, 'reason' => 'anchor_not_found'];
    }

    /**
     * Process a Replicator field value with custom href.
     *
     * @internal Public so the insert-parity audit can test replicator
     * inserts in isolation without disk-mutating an entry. Production
     * code goes through {@see BardLinkInserter::insertLinkIntoEntryWithHref}.
     */
    public static function processReplicatorWithHref(array $sets, string $anchorText, string $href, bool $caseSensitive = false, ?string $expectedSentenceContext = null): ?array
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
                    $modified = BardLinkInserter::insertLinkWithHref($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

                    if ($modified !== null) {
                        $sets[$i][$key] = $modified;

                        return $sets;
                    }
                } elseif (static::looksLikeReplicatorContent($value)) {
                    $modified = static::processReplicatorWithHref($value, $anchorText, $href, $caseSensitive, $expectedSentenceContext);

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
     * Insert a link at a position inside a Replicator value.
     *
     * Position-based Step-C path of the Bug 17-20 root refactor: takes
     * an explicit replicator_path + paragraph_path + char range, walks
     * the navigation by-reference, and delegates the wrap to
     * {@see BardLinkInserter::insertLinkAtPositionInBard}. NO find-first
     * walker, NO sentence-context guard — the position IS the truth.
     *
     * @param  list<array{set_index: int, key: string}>  $replicatorPath
     *
     * @return array{ok: bool, content?: array, reason?: string, blocking_href?: string}
     */
    public static function insertLinkAtPositionInReplicator(array $sets, string $anchorText, string $href, array $replicatorPath, array $paragraphPath, int $charStart, int $charEnd): array
    {
        if (empty($replicatorPath)) {
            return ['ok' => false, 'reason' => 'invalid_position'];
        }

        // Navigate the replicator path to the innermost Bard array, by-reference.
        $cursor = &$sets;
        $depth = count($replicatorPath);
        for ($i = 0; $i < $depth - 1; $i++) {
            $step = $replicatorPath[$i];
            if (! isset($cursor[$step['set_index']][$step['key']]) || ! is_array($cursor[$step['set_index']][$step['key']])) {
                return ['ok' => false, 'reason' => 'invalid_position'];
            }
            $cursor = &$cursor[$step['set_index']][$step['key']];
        }
        $lastStep = $replicatorPath[$depth - 1];
        $bardKey = $lastStep['key'];
        $setIndex = $lastStep['set_index'];
        if (! isset($cursor[$setIndex][$bardKey]) || ! is_array($cursor[$setIndex][$bardKey])) {
            return ['ok' => false, 'reason' => 'invalid_position'];
        }

        $result = BardLinkInserter::insertLinkAtPositionInBard(
            $cursor[$setIndex][$bardKey], $anchorText, $href, $paragraphPath, $charStart, $charEnd,
        );
        if (! ($result['ok'] ?? false)) {
            return $result;
        }
        $cursor[$setIndex][$bardKey] = $result['content'];

        return ['ok' => true, 'content' => $sets];
    }
}
