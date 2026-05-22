<?php

namespace Arturrossbach\Linkwise\Support;

/**
 * Pre-flight check for the shell-exec primitives Linkwise needs to dispatch
 * its bulk-job pipeline (Scan Content, Check Links, Bulk Unlink, Apply
 * Rule, URL Changer Apply, Inbound/Outbound Insert).
 *
 * ## Why this matters
 *
 * Linkwise spawns long-running artisan commands in the background via
 * `exec("$php $artisan ... &")` from `BulkJobDispatcher` (and a handful
 * of controller call sites). When `exec()` or `proc_open()` are disabled
 * via PHP's `disable_functions` ini directive — common on shared hosts
 * like IONOS Basic, Bluehost, GoDaddy — the background spawn silently
 * returns `false`, the JobLock writes a "starting" status that never
 * advances, and the user sees a Scan Content button that does nothing.
 *
 * This helper detects the situation up-front so the CP can show an
 * actionable banner instead of letting users hit the failure case.
 *
 * ## Detection
 *
 * Two layers:
 *  1. `function_exists()` — catches the case where the symbol is gone
 *     entirely (rare; usually means `disable_functions` killed it).
 *  2. `ini_get('disable_functions')` token-scan — catches the case
 *     where the function lives but ini directives have blacklisted it
 *     (the common shared-host pattern).
 *
 * Plus `safe_mode` (deprecated since 5.4 but still set by some legacy
 * hostings) as belt-and-braces.
 *
 * ## Cached per request
 *
 * The probe is cheap but called on every CP page load (via Inertia
 * props), so we memoize per-request — saves a couple of `ini_get`
 * calls and lets us inject test values cleanly via the reset method.
 */
class ExecAvailability
{
    private static ?array $cache = null;

    /**
     * Boolean shortcut: true when both `exec()` and `proc_open()` are
     * callable AND not blacklisted via `disable_functions`. False on
     * any restriction. UI banner reads this directly.
     */
    public static function available(): bool
    {
        $check = self::check();

        return $check['exec_available'] && $check['proc_open_available'];
    }

    /**
     * Detailed report — what's missing, which restriction layer
     * is responsible. Used by the CP banner to render specific
     * remediation copy and by the diagnostic-export tool.
     *
     * @return array{
     *   exec_available: bool,
     *   proc_open_available: bool,
     *   disabled_functions: list<string>,
     *   safe_mode: bool,
     * }
     */
    public static function check(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // PHP's `safe_mode` is removed since 5.4 — but some
        // shared-host control panels still advertise the toggle
        // and Statamic's minimum is PHP 8+ so it'll always be off
        // in practice. We probe for completeness, never expect a
        // hit. ini_get returns "" on removed directives.
        $safeMode = filter_var(ini_get('safe_mode') ?: '', FILTER_VALIDATE_BOOLEAN);

        $disabledRaw = (string) (ini_get('disable_functions') ?: '');
        $disabled = array_filter(array_map('trim', explode(',', $disabledRaw)));
        $disabledLower = array_map('strtolower', $disabled);

        $execAvailable = function_exists('exec')
            && ! in_array('exec', $disabledLower, true)
            && ! $safeMode;

        $procOpenAvailable = function_exists('proc_open')
            && ! in_array('proc_open', $disabledLower, true)
            && ! $safeMode;

        return self::$cache = [
            'exec_available' => $execAvailable,
            'proc_open_available' => $procOpenAvailable,
            'disabled_functions' => array_values($disabled),
            'safe_mode' => $safeMode,
        ];
    }

    /**
     * Test seam — lets the unit test inject a synthetic verdict
     * without faking `ini_get` globally. Production code never
     * calls this; only the suite uses it.
     *
     * @param  array{exec_available: bool, proc_open_available: bool, disabled_functions?: list<string>, safe_mode?: bool}|null  $override
     */
    public static function setForTesting(?array $override): void
    {
        if ($override === null) {
            self::$cache = null;

            return;
        }
        self::$cache = array_merge([
            'disabled_functions' => [],
            'safe_mode' => false,
        ], $override);
    }
}
