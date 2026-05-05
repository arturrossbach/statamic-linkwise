<?php

namespace Inkline\Linkwise\Support;

/**
 * Append-mode log helper for Linkwise's heavy-bulk command outputs.
 *
 * Why this exists: previously each `exec(... > $log 2>&1)` overwrote the
 * file, so a successful re-run of "Scan Content" wiped the failed prior
 * run's evidence. Now each run appends, with a visual separator + ISO
 * timestamp, and the file rotates at 5MB so it can't grow unbounded.
 */
class LogRotator
{
    /** Rotate at this byte size — keeps a single .1 backup. */
    protected const MAX_SIZE = 5 * 1024 * 1024;

    /**
     * Prepare a log path under storage/linkwise/, ensuring:
     *  - the directory exists
     *  - if the existing file is >5MB, it's rotated to <name>.1
     *  - a "── new run ──" separator + timestamp is appended
     *
     * Returns the absolute path. Caller pairs it with a shell `>>` redirect.
     */
    public static function prepare(string $logFilename, string $runLabel): string
    {
        $dir = storage_path('linkwise');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $path = $dir.'/'.$logFilename;

        if (file_exists($path) && filesize($path) > self::MAX_SIZE) {
            // Single-backup rotation: overwrite any previous .1 — we don't
            // need a long history of rotated logs, just "before" and "now".
            @rename($path, $path.'.1');
        }

        $separator = sprintf(
            "\n────────── %s @ %s ──────────\n",
            $runLabel,
            now()->toIso8601String(),
        );
        @file_put_contents($path, $separator, FILE_APPEND);

        return $path;
    }
}
