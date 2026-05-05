<?php

namespace Inkline\Linkwise\Support;

/**
 * Walks all content fields of a Statamic entry (Bard, Replicator, Markdown,
 * plain text/textarea) and calls visitor callbacks for each piece of content
 * found.
 *
 * Eliminates the duplicated field-traversal boilerplate across 8+ files.
 *
 * IMPORTANT: the $onMarkdown callback also fires for plain-string values
 * nested inside Replicator sets (card text fields, accordion bodies, button
 * labels, etc.). Treating those as "markdown" is safe — link extractors only
 * match `[text](url)` patterns and ignore non-markdown content. Without this
 * branch, a Peak site that uses Cards/Accordion/Quote sets had its non-Bard
 * content completely invisible to broken-link checks, domain reports, and
 * inbound/outbound link counting.
 */
class EntryFieldWalker
{
    /**
     * Walk all content fields of an entry.
     *
     * @param  \Statamic\Entries\Entry  $entry
     * @param  callable(array $bardContent): void  $onBard  Called with each Bard content array
     * @param  callable(string $markdown): void|null  $onMarkdown  Called with each Markdown string AND each plain-string value nested in a Replicator
     */
    public static function walk($entry, callable $onBard, ?callable $onMarkdown = null): void
    {
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable) {
            return;
        }

        foreach ($fields as $handle => $field) {
            $value = $entry->get($handle);

            if ($field->type() === 'bard' && is_array($value) && ! empty($value)) {
                $onBard($value);
            } elseif ($field->type() === 'replicator' && is_array($value) && ! empty($value)) {
                static::walkReplicator($value, $onBard, $onMarkdown);
            } elseif ($onMarkdown && $field->type() === 'markdown' && is_string($value) && ! empty($value)) {
                $onMarkdown($value);
            }
        }
    }

    /**
     * Recursively walk Replicator sets. Calls $onBard for each nested Bard
     * field, and $onMarkdown for each plain-string value that carries actual
     * content (after filtering UUIDs, numerics, booleans, and very short
     * strings to keep noise out of downstream link/keyword extraction).
     */
    public static function walkReplicator(array $sets, callable $onBard, ?callable $onMarkdown = null): void
    {
        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            foreach ($set as $key => $value) {
                // Skip replicator metadata keys (id, type, enabled)
                if (in_array($key, UrlHelper::REPLICATOR_META_KEYS, true)) {
                    continue;
                }

                // Plain string — treat as markdown so link extractors run.
                // Filtering goes through the shared InsertableContentFilter
                // so the read side (this walker) and the write side
                // (BardLinkInserter) reject the exact same shapes — no
                // asymmetry where the indexer would surface a URL/asset
                // string as content but the inserter would refuse to
                // touch it (or, worse, the other way around).
                if (is_string($value)) {
                    if (! $onMarkdown) {
                        continue;
                    }
                    if (! InsertableContentFilter::isContent($value, (string) $key)) {
                        continue;
                    }
                    $onMarkdown(trim($value));
                    continue;
                }

                if (! is_array($value) || empty($value)) {
                    continue;
                }

                if (ProseMirrorTypes::looksLikeBardContent($value)) {
                    $onBard($value);
                } elseif (isset($value[0]['type'], $value[0]['id'])) {
                    // Nested replicator
                    static::walkReplicator($value, $onBard, $onMarkdown);
                }
            }
        }
    }
}
