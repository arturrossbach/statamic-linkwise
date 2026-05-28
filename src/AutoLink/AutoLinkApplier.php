<?php

namespace Arturrossbach\Linkwise\AutoLink;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\ContextExtractor;
use Arturrossbach\Linkwise\Support\ProseMirrorTypes;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

class AutoLinkApplier
{
    /** @var string[] Entry IDs to skip (e.g. conflicted entries) */
    protected array $runtimeExcludedEntries = [];

    public function __construct(
        protected EntryIndexer $indexer,
        protected AutoLinkManager $manager,
    ) {}

    /**
     * Set additional entry IDs to skip during apply (e.g. conflicted entries).
     */
    public function setExcludedEntries(array $entryIds): void
    {
        $this->runtimeExcludedEntries = $entryIds;
    }

    /**
     * Apply a single rule to all matching entries.
     *
     * ─── Spec: Occurrence-Auswahl pro Beitrag ────────────────────────────
     *
     * Eine Rule verlinkt **die erste Fundstelle** des Keywords pro Beitrag.
     * Wenn das Keyword "Setup" mehrfach im Beitrag vorkommt, wird die erste
     * gültige Stelle (Wortgrenze + nicht bereits verlinkt + nicht in Skip-
     * Range) gewrapped — keine Heuristik die "die passendste Stelle" wählt.
     *
     * Begründung (REV-AL-03, 2026-05-13): AutoLink-Rules sind vom User
     * explizit definiert ("verlinke dieses Keyword mit dieser URL"). Der
     * User hat schon entschieden was er will; das Tool muss nicht raten,
     * welche Fundstelle die "passendste" ist. Plus: "first match wins" ist
     * ein verständliches mentales Modell — alternative Heuristiken würden
     * ihre eigene Bug-Klasse erzeugen ("warum DIESE Stelle?").
     *
     * Wenn die erste Fundstelle nicht passt, kann der User manuell über
     * den Re-Link-Flow verschieben.
     *
     * Diese Semantik ist Teil der V1-Spec, nicht Implementations-Zufall.
     *
     * ─────────────────────────────────────────────────────────────────────
     *
     * @param  ?callable  $shouldCancel  Optional cancel hook. Polled at each
     *                                   record boundary; when it returns true
     *                                   the loop exits early and `$result['cancelled']`
     *                                   is set. The partial result is returned
     *                                   so the caller can persist progress.
     * @return array{entries_checked: int, links_added: int, entries_skipped: int, affected_entries: array, cancelled?: bool}
     */
    public function applyRule(AutoLinkRule $rule, bool $preview = false, ?callable $onProgress = null, ?callable $shouldCancel = null): array
    {
        $records = $this->indexer->load();
        $result = [
            'entries_checked' => 0,
            'links_added' => 0,
            'entries_skipped' => 0,
            'affected_entries' => [],
        ];

        // Load globally excluded entries from settings
        $excludedEntries = config('linkwise.excluded_entries', []);
        $excludedEntries = is_array($excludedEntries) ? $excludedEntries : [];

        $totalRecords = count($records);
        $processed = 0;

        foreach ($records as $record) {
            // Cancel check at iteration boundary. Polled BEFORE expensive work
            // so a click on the Cancel button takes effect within ~one record
            // instead of running the full rule to completion. The partial
            // result still flows back to the caller via `cancelled => true`.
            if ($shouldCancel && $shouldCancel()) {
                $result['cancelled'] = true;

                return $result;
            }

            $processed++;
            if ($onProgress) {
                $onProgress($processed, $totalRecords, $result['links_added']);
            }
            // Skip globally excluded entries
            if (in_array($record->id, $excludedEntries, true)) {
                continue;
            }

            // Skip entries excluded at runtime (e.g. conflicted entries)
            if (in_array($record->id, $this->runtimeExcludedEntries, true)) {
                $result['entries_skipped']++;

                continue;
            }

            // Filter by collections if rule has restrictions
            if (! empty($rule->collections) && ! in_array($record->collection, $rule->collections, true)) {
                continue;
            }

            // V1.2 Cross-Tab-B — per-rule locale scope. Empty rule.locales =
            // match all (back-compat). Null entry-locale (single-site /
            // legacy record) passes too. Closes audit F1: a DE-rule no
            // longer fires on EN entries just because the keyword happens
            // to appear in both languages.
            if (! $rule->matchesLocale($record->locale)) {
                continue;
            }

            $result['entries_checked']++;

            // Skip self-referencing (entry linking to itself)
            if ($rule->targetEntryId && $rule->targetEntryId === $record->id) {
                $result['entries_skipped']++;

                continue;
            }

            // Check if entry text contains the keyword at a word boundary (Unicode-aware)
            if (! $this->textContainsKeywordAtBoundary($record->text, $rule->keyword, $rule->caseSensitive)) {
                $result['entries_skipped']++;

                continue;
            }

            // Build the href for this rule
            $href = $rule->targetEntryId
                ? 'statamic://entry::'.$rule->targetEntryId
                : $rule->url;

            // Determine link status: 'would_link', 'linked_to_target', 'linked_elsewhere'
            $linkedToTarget = false;
            if ($rule->targetEntryId) {
                $linkedToTarget = in_array($rule->targetEntryId, $record->outboundLinks, true);
            } else {
                $linkedToTarget = $this->entryContainsLink($record->id, $href);
            }

            $keywordHasAnyLink = $linkedToTarget || $this->keywordIsLinkedInEntry($record->id, $rule->keyword, $rule->caseSensitive);

            // Determine status
            $linkStatus = 'would_link';
            if ($linkedToTarget) {
                $linkStatus = 'linked_to_target';
            } elseif ($keywordHasAnyLink) {
                $linkStatus = 'linked_elsewhere';
            }

            // For would_link: verify Bard's insert logic can actually find a matching
            // text node. textContainsKeywordAtBoundary only checks the flattened text;
            // Bard may fail when the keyword spans nodes or sits inside non-text blocks.
            // This keeps Preview honest — the count reflects what Apply will truly insert.
            if ($linkStatus === 'would_link') {
                try {
                    $canInsert = $this->performInsert(
                        $record->id, $rule->keyword, $href, $rule->caseSensitive, false
                    );
                    if (! $canInsert) {
                        $linkStatus = 'not_insertable';
                    }
                } catch (\Throwable) {
                    $linkStatus = 'not_insertable';
                }
            }

            if ($preview) {
                // V1 behaviour: one link per entry (SEO best practice).
                // Preview shows the FIRST valid occurrence with the entry-level status.
                $occurrences = $this->findAllOccurrences($record->text, $rule->keyword, $rule->caseSensitive);
                if (! empty($occurrences)) {
                    $result['affected_entries'][] = [
                        'id' => $record->id,
                        'title' => $record->title,
                        'collection' => $record->collection,
                        'locale' => $record->locale,
                        'link_status' => $linkStatus,
                        'sentence_context' => $occurrences[0],
                    ];
                    if ($linkStatus === 'would_link') {
                        $result['links_added']++;
                    }
                }

                continue;
            }

            // Skip when keyword is already linked to this rule's target — nothing to do
            if ($linkStatus === 'linked_to_target') {
                $result['entries_skipped']++;

                continue;
            }

            // Skip when keyword has any link and skip_if_exists is enabled
            if ($linkStatus === 'linked_elsewhere' && $rule->skipIfExists) {
                $result['entries_skipped']++;

                continue;
            }

            // Note: even when skipIfExists is false and linkStatus is 'linked_elsewhere',
            // BardLinkInserter cannot overwrite an existing link mark. The text node already
            // has a link and will be skipped. To change the URL, use the URL Changer instead.

            // V1: single-insert always (oncePerPost=true is enforced).
            // Bug 3 (2026-05-11): a per-record exception (EntryConflictException
            // from a parallel edit, malformed bard, disk failure, anything
            // \Throwable) used to bubble up out of this loop, out of applyRule,
            // into ApplyRuleCommand's outer try/catch — which set phase=error
            // and aborted the whole rule. Records AFTER the failing one were
            // never even tried. Now we trap per-record so the bulk continues.
            $inserted = false;
            try {
                $inserted = $this->performInsert(
                    $record->id,
                    $rule->keyword,
                    $href,
                    $rule->caseSensitive,
                );
            } catch (\Throwable $e) {
                Log::warning('[Linkwise] AutoLinkApplier insert failed for entry '.$record->id.': '.$e->getMessage());
                $result['errors'] = $result['errors'] ?? [];
                $errKey = mb_substr($e->getMessage(), 0, 120);
                $result['errors'][$errKey] = ($result['errors'][$errKey] ?? 0) + 1;
            }

            if ($inserted) {
                $result['links_added']++;
                $result['affected_entries'][] = [
                    'id' => $record->id,
                    'title' => $record->title,
                    'collection' => $record->collection,
                ];
            } else {
                $result['entries_skipped']++;
            }
        }

        if ($preview) {
            $result['total_linked_to_target'] = count(array_filter(
                $result['affected_entries'],
                fn ($e) => ($e['link_status'] ?? '') === 'linked_to_target',
            ));
            $result['total_linked_elsewhere'] = count(array_filter(
                $result['affected_entries'],
                fn ($e) => ($e['link_status'] ?? '') === 'linked_elsewhere',
            ));
        }

        return $result;
    }

    /**
     * Thin seam over BardLinkInserter::insertLinkIntoEntryWithHref.
     *
     * Exists for two reasons:
     *   1. Tests can subclass and override to simulate per-record exceptions
     *      without a Statamic test environment (Bug 3 regression coverage).
     *   2. Both the preview-time can-insert probe AND the real apply call
     *      route through one place — easier to add cross-cutting concerns
     *      (logging, metrics) later without duplicating.
     *
     * The signature mirrors BardLinkInserter exactly. No behaviour change.
     */
    protected function performInsert(string $entryId, string $keyword, string $href, bool $caseSensitive, bool $save = true, ?string $expectedSentenceContext = null): bool
    {
        return BardLinkInserter::insertLinkIntoEntryWithHref(
            $entryId, $keyword, $href, $caseSensitive, $save, $expectedSentenceContext
        );
    }

    /**
     * Find all occurrences of a keyword in text, returning context for each.
     *
     * @return string[]  Array of context snippets
     */
    /**
     * Check if text contains keyword at a Unicode-aware word boundary.
     */
    protected function textContainsKeywordAtBoundary(string $text, string $keyword, bool $caseSensitive): bool
    {
        $urlRanges = $this->markdownUrlRangesIn($text);
        $pos = $caseSensitive ? mb_strpos($text, $keyword) : mb_stripos($text, $keyword);

        while ($pos !== false) {
            // Skip matches that sit inside an existing markdown link's URL
            // portion. The text comes from EntryIndexer flattening which —
            // for some content paths (Bard/Replicator) — exports `[X](url)`
            // syntax verbatim. Without this filter, a rule like "statamic"
            // matches inside `(statamic://entry::…)` and mis-marks the entry
            // as "would_link" when in fact the URL is already a link target.
            if ($this->positionInRanges($pos, $urlRanges)) {
                $pos = $caseSensitive
                    ? mb_strpos($text, $keyword, $pos + mb_strlen($keyword))
                    : mb_stripos($text, $keyword, $pos + mb_strlen($keyword));

                continue;
            }
            $atBoundary = true;
            if ($pos > 0 && preg_match('/[\p{L}\p{N}]/u', mb_substr($text, $pos - 1, 1))) {
                $atBoundary = false;
            }
            $afterPos = $pos + mb_strlen($keyword);
            if ($afterPos < mb_strlen($text) && preg_match('/[\p{L}\p{N}]/u', mb_substr($text, $afterPos, 1))) {
                $atBoundary = false;
            }
            if ($atBoundary) {
                return true;
            }
            $pos = $caseSensitive
                ? mb_strpos($text, $keyword, $pos + mb_strlen($keyword))
                : mb_stripos($text, $keyword, $pos + mb_strlen($keyword));
        }

        return false;
    }

    /**
     * Compute character-position ranges of every markdown link URL portion
     * `[anchor](URL_HERE)` in the given text. Used to skip keyword matches
     * that fall inside an existing link's URL — they would never produce
     * an insertable link, but `findAllOccurrences` would otherwise report
     * misleading sentence_context like "...[Modern web development](sta**mic**://...".
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function markdownUrlRangesIn(string $text): array
    {
        $ranges = [];
        if (preg_match_all('/\[[^\]]*\]\(([^\)]+)\)/u', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $ranges;
        }
        foreach ($matches[1] as [$urlPortion, $byteOffset]) {
            $charStart = mb_strlen(substr($text, 0, (int) $byteOffset));
            $charEnd = $charStart + mb_strlen($urlPortion);
            $ranges[] = [$charStart, $charEnd];
        }

        return $ranges;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    protected function positionInRanges(int $pos, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($pos >= $start && $pos < $end) {
                return true;
            }
        }

        return false;
    }

    protected function findAllOccurrences(string $text, string $keyword, bool $caseSensitive): array
    {
        $contexts = [];
        $offset = 0;
        $keywordLen = mb_strlen($keyword);
        $textLen = mb_strlen($text);
        $maxChars = 120;
        // Pre-compute URL-portion ranges of any markdown links in the text
        // so we can skip matches that fall inside `(…)` of `[X](…)`. Same
        // motivation as in textContainsKeywordAtBoundary above — `$record->text`
        // sometimes carries raw markdown verbatim (Bard / Replicator export
        // paths) and a rule like "statamic" matches `(statamic://entry::…)`,
        // producing misleading sentence_context the user can't act on.
        $urlRanges = $this->markdownUrlRangesIn($text);

        while (($pos = $caseSensitive ? mb_strpos($text, $keyword, $offset) : mb_stripos($text, $keyword, $offset)) !== false) {
            if ($this->positionInRanges($pos, $urlRanges)) {
                $offset = $pos + $keywordLen;

                continue;
            }
            // Word boundary check
            $atBoundary = true;
            if ($pos > 0 && preg_match('/[\p{L}\p{N}]/u', mb_substr($text, $pos - 1, 1))) {
                $atBoundary = false;
            }
            $afterPos = $pos + $keywordLen;
            if ($afterPos < $textLen && preg_match('/[\p{L}\p{N}]/u', mb_substr($text, $afterPos, 1))) {
                $atBoundary = false;
            }

            if ($atBoundary) {
                // Extract context directly at this position. Wrap the matched substring
                // with sentinel chars (\x01 ... \x02) so the frontend can highlight the
                // EXACT occurrence that triggered this row — not just the first textual
                // match, which fails when contexts overlap (multiple matches close together).
                $halfWindow = (int) max(20, floor(($maxChars - $keywordLen) / 2));
                $start = max(0, $pos - $halfWindow);
                $end = min($textLen, $pos + $keywordLen + $halfWindow);
                $rawContext = mb_substr($text, $start, $end - $start);
                $matchStartInContext = $pos - $start;
                $matchedText = mb_substr($rawContext, $matchStartInContext, $keywordLen);
                $marked = mb_substr($rawContext, 0, $matchStartInContext)
                    ."\x01".$matchedText."\x02"
                    .mb_substr($rawContext, $matchStartInContext + $keywordLen);
                $prefix = $start > 0 ? '...' : '';
                $suffix = $end < $textLen ? '...' : '';
                $contexts[] = $prefix.trim($marked).$suffix;
            }

            $offset = $pos + $keywordLen;
        }

        // Return only real matches. The earlier behaviour fell back to a
        // single "extracted context" when no boundary-match was found —
        // but new callers iterate per text-node, where most nodes legitimately
        // contain no keyword, and the fallback turned every empty node into
        // a phantom row. Callers must now handle the empty case themselves.
        return $contexts;
    }

    /**
     * Check if an entry's Bard content already contains a link with the given href.
     */
    protected function entryContainsLink(string $entryId, string $href): bool
    {
        $entry = Entry::find($entryId);

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

            // Check Markdown fields for link href
            if ($field->type() === 'markdown' && is_string($value) && ! empty($value)) {
                if (str_contains($value, ']('.$href.')')) {
                    return true;
                }

                continue;
            }

            if (! is_array($value) || empty($value)) {
                continue;
            }

            if ($field->type() === 'bard') {
                if ($this->bardContainsHref($value, $href)) {
                    return true;
                }
            } elseif ($field->type() === 'replicator') {
                if ($this->replicatorContainsHref($value, $href)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a keyword's text is already inside a link mark in an entry (linked to any target).
     */
    protected function keywordIsLinkedInEntry(string $entryId, string $keyword, bool $caseSensitive): bool
    {
        $entry = Entry::find($entryId);

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

            if ($field->type() === 'markdown' && is_string($value) && ! empty($value)) {
                // Check if keyword appears inside a Markdown link: [keyword](...)
                $escaped = preg_quote($keyword, '/');
                $pattern = $caseSensitive ? "/\[{$escaped}\]\(/" : "/\[{$escaped}\]\(/i";
                if (preg_match($pattern, $value)) {
                    return true;
                }

                continue;
            }

            if (! is_array($value) || empty($value)) {
                continue;
            }

            if ($field->type() === 'bard' || $field->type() === 'replicator') {
                if ($this->bardHasLinkedKeyword($value, $keyword, $caseSensitive)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function bardHasLinkedKeyword(array $content, string $keyword, bool $caseSensitive): bool
    {
        return \Arturrossbach\Linkwise\Support\BardWalker::walk($content, function (array $node) use ($keyword, $caseSensitive): bool {
            if (! isset($node['text'], $node['marks'])) {
                return false;
            }
            $hasLink = false;
            foreach ($node['marks'] as $mark) {
                if (($mark['type'] ?? '') === 'link') {
                    $hasLink = true;
                    break;
                }
            }
            if (! $hasLink) {
                return false;
            }

            return $caseSensitive
                ? mb_strpos($node['text'], $keyword) !== false
                : mb_stripos($node['text'], $keyword) !== false;
        });
    }

    protected function bardContainsHref(array $bardContent, string $href): bool
    {
        return \Arturrossbach\Linkwise\Support\BardWalker::walk($bardContent, function (array $node) use ($href): bool {
            if (! isset($node['marks'])) {
                return false;
            }
            foreach ($node['marks'] as $mark) {
                if (($mark['type'] ?? '') === 'link' && ($mark['attrs']['href'] ?? '') === $href) {
                    return true;
                }
            }
            return false;
        });
    }


    protected function replicatorContainsHref(array $sets, string $href): bool
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
                    if ($this->bardContainsHref($value, $href)) {
                        return true;
                    }
                } elseif (is_array($value) && isset($value[0]['type'], $value[0]['id'])) {
                    if ($this->replicatorContainsHref($value, $href)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }


}
