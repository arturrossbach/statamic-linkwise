<?php

namespace Arturrossbach\Linkwise\Support;

/**
 * Resolve the CLI PHP binary path for `exec()` of detached `artisan` commands.
 *
 * Why this exists:
 * Under PHP-FPM (= every HTTP request to Herd/nginx), the `PHP_BINARY` constant
 * evaluates to the FPM binary (e.g. `php84-fpm`), NOT the CLI one. Passing the
 * FPM binary to `exec("$php $artisan ...")` results in `php84-fpm` interpreting
 * the artisan path as an FPM argument — it prints its usage and exits without
 * running the command. The bulk job's status cache stays at `phase=starting`
 * forever; the heavy-job banner spins; nothing happens.
 *
 * Empirically reproduced 2026-05-12 on Herd: `DetailUnlinkAsync` exec'd
 * `/Users/.../Herd/bin/php84-fpm artisan linkwise:detail-unlink ...` and the
 * log file captured the FPM usage banner instead of any DetailUnlinkCommand
 * output.
 *
 * Strategy:
 * 1. Try Symfony's PhpExecutableFinder — works in CLI, may also yield CLI
 *    binary under FPM if PATH lookup succeeds.
 * 2. If the result still contains `-fpm`, strip the suffix and check whether
 *    the stripped path is an executable file (FPM and CLI binaries usually
 *    sit side-by-side in the same bin directory).
 * 3. Last resort: return `'php'` and rely on the shell's `$PATH` to resolve.
 *
 * All 9 controllers that dispatch detached artisan commands should call
 * `PhpBinary::cli()` instead of using `PHP_BINARY` directly.
 */
class PhpBinary
{
    public static function cli(): string
    {
        $finder = new \Symfony\Component\Process\PhpExecutableFinder();
        $candidate = $finder->find(false);

        if (is_string($candidate) && $candidate !== '' && ! str_contains($candidate, '-fpm')) {
            return $candidate;
        }

        // FPM-binary detected — try the sibling CLI binary.
        if (is_string($candidate) && str_contains($candidate, '-fpm')) {
            $stripped = str_replace('-fpm', '', $candidate);
            if (is_executable($stripped)) {
                return $stripped;
            }
        }

        // Last resort: rely on $PATH. Herd / Laravel Valet / mainstream
        // PHP installs all have `php` resolvable from a CLI-spawned shell.
        return 'php';
    }
}
