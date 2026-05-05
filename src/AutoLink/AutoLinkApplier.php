<?php

namespace Inkline\Linkwise\AutoLink;

use Inkline\Linkwise\Indexer\EntryIndexer;
use Inkline\Linkwise\Support\BardLinkInserter;
use Inkline\Linkwise\Support\ContextExtractor;
use Inkline\Linkwise\Support\ProseMirrorTypes;
use Inkline\Linkwise\Support\UrlHelper;
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
     * @return array{entries_checked: int, links_added: int, entries_skipped: int, affected_entries: array}
     */
    public function applyRule(AutoLinkRule $rule, bool $preview = false, ?callable $onProgress = null): array
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
                    $canInsert = BardLinkInserter::insertLinkIntoEntryWithHref(
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
            $inserted = BardLinkInserter::insertLinkIntoEntryWithHref(
                $record->id,
                $rule->keyword,
                $href,
                $rule->caseSensitive,
            );

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
     * Find all occurrences of a keyword in text, returning context for each.
     *
     * @return string[]  Array of context snippets
     */
    /**
     * Check if text contains keyword at a Unicode-aware word boundary.
     */
    protected function textContainsKeywordAtBoundary(string $text, string $keyword, bool $caseSensitive): bool
    {
        $pos = $caseSensitive ? mb_strpos($text, $keyword) : mb_stripos($text, $keyword);

        while ($pos !== false) {
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

    protected function findAllOccurrences(string $text, string $keyword, bool $caseSensitive): array
    {
        $contexts = [];
        $offset = 0;
        $keywordLen = mb_strlen($keyword);
        $textLen = mb_strlen($text);
        $maxChars = 120;

        while (($pos = $caseSensitive ? mb_strpos($text, $keyword, $offset) : mb_stripos($text, $keyword, $offset)) !== false) {
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
        foreach ($content as $node) {
            // Text node with link mark containing the keyword
            if (isset($node['text'], $node['marks'])) {
                $hasLink = false;
                foreach ($node['marks'] as $mark) {
                    if (($mark['type'] ?? '') === 'link') {
                        $hasLink = true;
                        break;
                    }
                }

                if ($hasLink) {
                    $match = $caseSensitive
                        ? mb_strpos($node['text'], $keyword) !== false
                        : mb_stripos($node['text'], $keyword) !== false;
                    if ($match) {
                        return true;
                    }
                }
            }

            // Recurse into children
            if (isset($node['content']) && is_array($node['content'])) {
                if ($this->bardHasLinkedKeyword($node['content'], $keyword, $caseSensitive)) {
                    return true;
                }
            }

            // Recurse into replicator sets
            foreach ($node as $key => $value) {
                if (in_array($key, ['type', 'id', 'enabled', 'text', 'marks', 'content', 'attrs'], true)) {
                    continue;
                }
                if (is_array($value) && ! empty($value)) {
                    if ($this->bardHasLinkedKeyword($value, $keyword, $caseSensitive)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function bardContainsHref(array $bardContent, string $href): bool
    {
        foreach ($bardContent as $node) {
            if ($this->nodeContainsHref($node, $href)) {
                return true;
            }
        }

        return false;
    }

    protected function nodeContainsHref(array $node, string $href): bool
    {
        if (isset($node['marks'])) {
            foreach ($node['marks'] as $mark) {
                if (($mark['type'] ?? '') === 'link' && ($mark['attrs']['href'] ?? '') === $href) {
                    return true;
                }
            }
        }

        if (isset($node['content'])) {
            foreach ($node['content'] as $child) {
                if ($this->nodeContainsHref($child, $href)) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * Apply all active rules.
     *
     * @return array{total_rules: int, total_links_added: int, results: array}
     */
    public function applyAll(bool $preview = false): array
    {
        $rules = $this->manager->loadRules();
        $totalLinksAdded = 0;
        $results = [];

        foreach ($rules as $rule) {
            if (! $rule->active) {
                continue;
            }

            $result = $this->applyRule($rule, $preview);
            $totalLinksAdded += $result['links_added'];
            $results[] = [
                'rule_id' => $rule->id,
                'keyword' => $rule->keyword,
                'links_added' => $result['links_added'],
                'entries_checked' => $result['entries_checked'],
            ];
        }

        return [
            'total_rules' => count($results),
            'total_links_added' => $totalLinksAdded,
            'results' => $results,
        ];
    }

}
