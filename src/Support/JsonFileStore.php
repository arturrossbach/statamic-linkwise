<?php

namespace Arturrossbach\Linkwise\Support;

use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for "load + parse a JSON state file safely".
 *
 * Linkwise has 7+ JSON-backed state files (linkwise-index.json,
 * domain-attributes.json, target-keywords.json, autolink-rules.json,
 * broken-links.json, ignored-links.json) loaded from various readers.
 * Before this helper each reader did
 *
 *     $data = json_decode(file_get_contents($path), true);
 *     return is_array($data) ? $data : [];
 *
 * with subtle drift across sites: some checked file_exists first,
 * some swallowed file_get_contents=false silently, some returned []
 * on parse failure with NO log signal at all. That last one is
 * Linkwise's worst-case: a corrupted file makes the CP show empty
 * results forever (until the next full Scan/Rebuild) without any
 * trace in the logs for the operator to investigate.
 *
 * This helper centralises:
 *   - the "missing file → default, no warning" path (legitimate
 *     fresh-install state)
 *   - the "file unreadable → default, log warning" path (perms,
 *     race after file_exists, NFS hiccup)
 *   - the "file malformed → default, log warning" path (mid-write
 *     truncation, manual edit error). This is the SEO-silent class
 *     LinkwiseLinkMark hit before this helper existed.
 *
 * Single point to evolve the corruption-detection logic — adding
 * structural validation (e.g. "must be an array") becomes one edit
 * instead of seven.
 */
class JsonFileStore
{
    /**
     * Load + JSON-decode a state file.
     *
     * @param  string  $path     Absolute path to the JSON file.
     * @param  mixed   $default  Value to return on any failure (typically []).
     * @param  string  $logTag   Caller identifier shown in warning logs
     *                           (e.g. "EntryIndexer::load"). Lets the
     *                           operator find WHICH reader hit a corrupt
     *                           file when triaging a "Linkwise shows empty"
     *                           support report.
     * @return mixed  The decoded data, or $default on missing file
     *                / read failure / parse failure / wrong-shape.
     */
    public static function load(string $path, mixed $default, string $logTag): mixed
    {
        if (! file_exists($path)) {
            // Fresh install / never-saved state. Not an error.
            return $default;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            // File disappeared between file_exists and read (race) or
            // perms changed mid-request. Log so the operator can find
            // it; readers degrade to default rather than crash.
            Log::warning(
                "[Linkwise] {$logTag}: could not read {$path} — falling back to default",
            );
            return $default;
        }

        // Empty file is treated as "no data yet" — common for newly-
        // created-then-truncated stores (e.g. cleared via the CP).
        // Avoids an "Unexpected end of JSON input" warning for a
        // legitimate empty state.
        if ($raw === '') {
            return $default;
        }

        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // Real parse error — not just "the file legitimately holds
            // null". Log + return default. This is the SEO-silent class
            // (LinkwiseLinkMark with corrupt domain-attributes.json):
            // before this log shipped, the public site silently dropped
            // rel attributes for weeks before someone noticed in Search
            // Console.
            $err = json_last_error_msg();
            Log::warning(
                "[Linkwise] {$logTag}: {$path} is not valid JSON ({$err}) — falling back to default",
            );
            return $default;
        }

        return $data;
    }
}
