<?php

namespace Arturrossbach\Linkwise\Support;

/**
 * Walks all content fields of a Statamic entry (Bard, Replicator, Markdown)
 * and calls visitor callbacks for each piece of structured content found.
 *
 * Eliminates the duplicated field-traversal boilerplate across 8+ files.
 *
 * Plain-string values (`text`/`textarea` field types, plus plain-string keys
 * nested in Replicator sets) are deliberately NOT visited. Statamic's contract
 * for those types is plaintext rendering, so `[anchor](url)` syntax there is
 * literal text — not a link. Treating it as a link would mean reporting
 * outbound links Linkwise cannot reach (the write side does not modify
 * plaintext fields either), creating ghost links the user could see in
 * DetailModal but never remove.
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
     * fragment. Plain-string values are skipped (see class docblock for why).
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
