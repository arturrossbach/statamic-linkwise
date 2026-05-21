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
        } catch (\Throwable $e) {
            // Don't crash the caller when an entry has a malformed blueprint
            // (schema-drift after a custom-field rename, missing fieldset
            // include, etc.) — but DO log: a silent return-empty here makes
            // the entry invisible to the indexer / suggestion engine without
            // any user-visible signal. The user sees "0 outbound links" on
            // an entry that clearly has links and has no clue why. Surfaced
            // here, the warning lands in storage/logs/laravel.log where the
            // user can find the entry id and fix the blueprint.
            $entryId = method_exists($entry, 'id') ? (string) $entry->id() : '?';
            \Illuminate\Support\Facades\Log::warning(
                "[Linkwise] EntryFieldWalker: blueprint load failed for entry {$entryId} — entry will be skipped: ".$e->getMessage(),
            );
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
     * Walk linkable content fields (Bard / Replicator / Markdown) of an entry
     * until ONE callback signals a mutation. Then writes the new value back
     * via $entry->set() and returns the {handle, field_type, result} record.
     *
     * Returns null if no callback signaled mutation.
     *
     * Callbacks receive the field's current value and return either NULL
     * (no mutation — try the next field) or an array `['value' => $new, ...]`
     * (mutation happened; $new is written to the entry; the caller may carry
     * additional keys in the result for downstream consumers — see Re-Link
     * Step A which carries 'position' for Step C's exact-position insert).
     *
     * Callbacks MUST be idempotent up to inspection — if `value` returned
     * equals the input value, the result is still treated as a mutation
     * (set is called). Callbacks that decide late they did nothing should
     * return null.
     *
     * REV-RL-02 (2026-05-13): extracted from RelinkService::relink Step-A
     * field-cascade. Helper Infrastructure für künftige first-mutation-
     * pattern Stellen.
     *
     * @param  callable(array): (?array)  $onBard
     * @param  callable(array): (?array)  $onReplicator
     * @param  callable(string): (?array)  $onMarkdown
     * @return array{handle: string, field_type: string, result: array}|null
     */
    public static function firstMutation(
        $entry,
        callable $onBard,
        callable $onReplicator,
        callable $onMarkdown,
    ): ?array {
        try {
            $fields = $entry->blueprint()->fields()->all();
        } catch (\Throwable $e) {
            $entryId = method_exists($entry, 'id') ? (string) $entry->id() : '?';
            \Illuminate\Support\Facades\Log::warning(
                "[Linkwise] EntryFieldWalker::firstMutation: blueprint load failed for entry {$entryId}: ".$e->getMessage(),
            );
            return null;
        }

        foreach ($fields as $handle => $field) {
            $type = $field->type();
            $value = $entry->get($handle);

            $result = match ($type) {
                'bard' => (is_array($value) && ! empty($value)) ? $onBard($value) : null,
                'replicator' => (is_array($value) && ! empty($value)) ? $onReplicator($value) : null,
                // Markdown skips the 'title' handle by convention — title is
                // never a markdown link host even when the blueprint declares
                // it as markdown-typed. Mirrors the existing inline-cascade
                // skip in RelinkService Z. 162 + BardLinkInserter Z. 408.
                'markdown' => (is_string($value) && $value !== '' && $handle !== 'title') ? $onMarkdown($value) : null,
                default => null,
            };

            if ($result === null) {
                continue;
            }

            // Callback signaled mutation — write back + return.
            if (! array_key_exists('value', $result)) {
                throw new \InvalidArgumentException(
                    'EntryFieldWalker::firstMutation: callback must return array with "value" key when signaling mutation'
                );
            }
            $entry->set($handle, $result['value']);

            return [
                'handle' => $handle,
                'field_type' => $type,
                'result' => $result,
            ];
        }

        return null;
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
