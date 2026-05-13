<?php

namespace Arturrossbach\Linkwise\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Dispatches a detached artisan command for a bulk operation.
 *
 * REV-XC-01 (2026-05-13): the 5-step boilerplate (cache-forget stale
 * status/cancel → cache-put payload → cache-put initial status → escapeshell
 * args → exec) was duplicated across 9 controller methods. The boilerplate
 * is fragile (Bug 21 was a missed cli-binary in one of these sites; the
 * heartbeat dedup-signature bug spanned multiple sites). Single helper +
 * single test surface.
 *
 * Two dispatch shapes supported:
 *   - "Bulk-with-payload": ['payload' => [...], 'initialStatus' => [...]]
 *     (LinkInsertCommand / DetailUnlinkCommand / UrlChangerApplyCommand /
 *      ApplyRuleCommand / BulkUnlinkCommand consumers)
 *   - "Progress-only": ['initialStatus' => ['phase' => 'starting']] +
 *     ['extraArgs' => ['--progress']]
 *     (CheckLinksCommand / IndexCommand consumers)
 *
 * The artisan command name (e.g. 'linkwise:link-insert') is what is exec'd.
 * The `$kind` parameter namespaces the cache keys (`linkwise:{kind}:status`
 * / `:payload` / `:cancel`); JobLock uses the same `$kind` so the busy-job
 * 409 is consistent with the cache shape this helper wrote.
 */
class BulkJobDispatcher
{
    private const DEFAULT_PAYLOAD_TTL = 600;
    private const DEFAULT_STATUS_TTL = 600;

    /**
     * @param  string  $kind  JobLock-kind namespace (e.g. 'outboundinsert', 'check')
     * @param  string  $command  Artisan command (e.g. 'linkwise:link-insert')
     * @param  array<string, mixed>|null  $payload  Bulk payload — null = no payload write
     * @param  array<string, mixed>  $initialStatus  Initial status-cache content (typically phase=starting + counters)
     * @param  string  $logFile  Log file name (e.g. 'link-insert.log')
     * @param  string  $logLabel  Log section label (e.g. 'Link Insert')
     * @param  list<string>  $extraArgs  Optional extra artisan args (e.g. ['--progress'])
     */
    public static function dispatch(
        string $kind,
        string $command,
        ?array $payload,
        array $initialStatus,
        string $logFile,
        string $logLabel,
        array $extraArgs = [],
        int $payloadTtl = self::DEFAULT_PAYLOAD_TTL,
        int $statusTtl = self::DEFAULT_STATUS_TTL,
    ): void {
        $statusKey = "linkwise:{$kind}:status";
        $payloadKey = "linkwise:{$kind}:payload";
        $cancelKey = "linkwise:{$kind}:cancel";

        // Wipe stale terminal-status + cancel-flag from any previous run so
        // the poller does not confuse it with the new dispatch.
        Cache::forget($statusKey);
        Cache::forget($cancelKey);

        // Persist payload first — the detached command reads it on startup.
        if ($payload !== null) {
            Cache::put($payloadKey, $payload, $payloadTtl);
        }

        // Initial status so the frontend poll sees something the moment
        // the dispatch returns. The detached command will overwrite this
        // with 'running' immediately on startup.
        Cache::put($statusKey, $initialStatus, $statusTtl);

        // Detached exec. PhpBinary::cli() resolves to a CLI php binary —
        // FPM binaries print a usage banner and exit (Bug 21 / Klasse 2),
        // so the helper here gives the failure mode one consistent owner.
        $artisan = escapeshellarg(base_path('artisan'));
        $php = escapeshellarg(PhpBinary::cli());
        $log = escapeshellarg(LogRotator::prepare($logFile, $logLabel));

        $argsClause = '';
        foreach ($extraArgs as $arg) {
            $argsClause .= ' '.escapeshellarg($arg);
        }

        exec("$php $artisan {$command}{$argsClause} >> $log 2>&1 &");
    }
}
