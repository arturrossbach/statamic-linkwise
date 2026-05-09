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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $json) === false) {
            Log::warning("[Linkwise] {$logTag}: could not write temp file {$tmp}");
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
        return file_put_contents($path, $json) !== false;
    }
}
