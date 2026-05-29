<?php

namespace Arturrossbach\Linkwise\Support;

use Illuminate\Support\Facades\Log;

/**
 * Atomic JSON file writer.
 *
 * Direct file_put_contents leaves the target truncated when the writing
 * process is killed mid-write (kill -9, OOM, server restart). Linkwise
 * stores several index/state JSON files (linkwise-index.json,
 * domain-attributes.json, broken-links.json, autolink-rules.json) where
 * such corruption would silently degrade the CP — readers fall back to
 * empty defaults without any user-visible signal.
 *
 * Single source of truth for the "stage to .tmp, then rename" pattern.
 * Both EntryIndexer::save and DomainReport::saveAttributes route through
 * this helper. Adding a third caller is one line, not 30.
 *
 * Behavior on edge filesystems:
 *   - rename() failed (cross-device, perms): falls back to direct write
 *     so behaviour degrades to pre-helper baseline rather than silently
 *     keeping the old file. Log carries enough info to diagnose.
 *   - tmp write failed (out of space, perms): nothing touches the
 *     target file. Caller can decide whether to retry or surface.
 */
class AtomicJsonWriter
{
    /**
     * Write $data to $path atomically.
     *
     * @param  string  $path     Absolute path to the target JSON file.
     * @param  mixed   $data     Anything json_encode can serialise.
     * @param  string  $logTag   Caller identifier shown in warning logs
     *                           (e.g. "EntryIndexer::save"). Lets the
     *                           operator trace WHICH writer hit a
     *                           filesystem issue.
     * @return bool  True when the data hit disk in the target file
     *               (atomically or via fallback). False when even the
     *               fallback failed — caller should NOT trust that
     *               the file reflects $data.
     */
    public static function write(string $path, mixed $data, string $logTag): bool
    {
        // H-1 (Code-Review 2026-05-29): json_encode returns false for
        // unencodable data (malformed UTF-8 byte in entry content, recursion,
        // depth limit). Guard BEFORE touching disk — otherwise
        // file_put_contents($tmp, false) writes an empty string and returns
        // 0 (not false), the `=== false` check below passes, rename() commits
        // it, and the target index/state file is atomically clobbered to
        // empty while write() reports success. Keep the previous file intact.
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            Log::error(
                "[Linkwise] {$logTag}: json_encode failed (".json_last_error_msg().") — target file left untouched",
            );

            return false;
        }

        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));

        // Byte-exact check on the staging write too (not just the fallback
        // below): a short write to $tmp followed by a successful rename would
        // otherwise atomically install a truncated file. file_put_contents
        // returns the written byte count or false; anything other than the
        // full length means the staging file is incomplete.
        $tmpWritten = @file_put_contents($tmp, $json);
        $expectedBytes = strlen($json);
        if ($tmpWritten === false || $tmpWritten !== $expectedBytes) {
            @unlink($tmp);
            Log::warning(
                "[Linkwise] {$logTag}: temp write incomplete (wrote ".(is_int($tmpWritten) ? (string) $tmpWritten : 'false')." bytes of {$expectedBytes}); target file left untouched",
            );

            return false;
        }

        if (@rename($tmp, $path)) {
            return true;
        }

        // Cross-device rename failed (rare on a single-disk Statamic
        // deploy but possible with NFS / bind mounts). Clean up the
        // staging file and fall back to direct write so behaviour
        // matches the pre-helper baseline rather than silently keeping
        // the old file.
        @unlink($tmp);
        Log::warning(
            "[Linkwise] {$logTag}: rename() failed (cross-device or perms?); falling back to direct write — atomicity lost for this save",
        );

        // CR-H-3 fix: previously `!== false` was the only check, which
        // misses partial-write scenarios (file_put_contents on a disk
        // about to run out of space can return a smaller-than-requested
        // byte count without raising). Verify byte-exact match — a short
        // write means the target file is truncated and the caller must
        // not trust this save.
        $written = file_put_contents($path, $json);
        $expectedBytes = strlen($json);
        if ($written === false || $written !== $expectedBytes) {
            Log::error(
                "[Linkwise] {$logTag}: fallback direct write produced truncated/failed output (wrote ".(is_int($written) ? (string) $written : 'false')." bytes of {$expectedBytes})",
            );

            return false;
        }

        return true;
    }
}
