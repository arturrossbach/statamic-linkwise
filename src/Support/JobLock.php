<?php

namespace Arturrossbach\Linkwise\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Single-job concurrency lock for Linkwise's background operations.
 *
 * Linkwise has four bulk operations that all touch the entry index:
 *   - Index rebuild (`linkwise:scan:status`)
 *   - Broken-link check (`linkwise:check:status`)
 *   - Bulk unlink (`linkwise:bulkunlink:status`)
 *   - Apply auto-link rule (`linkwise:applyrule:status`)
 *
 * Running two of them in parallel races on `index.json` and on individual
 * entry files (last writer wins, the other's changes lost from the index).
 * V1 enforces "one bulk job at a time" — simple, safe, easy to reason about.
 *
 * Concurrency primitives can be added in V1.1 (file lock + parallel-safe
 * index merging). For V1 this guard is the right trade-off.
 */
class JobLock
{
    /**
     * Status cache keys + human labels for each background job.
     */
    public const JOBS = [
        'scan' => ['key' => 'linkwise:scan:status', 'label' => 'content scan'],
        'check' => ['key' => 'linkwise:check:status', 'label' => 'broken-link check'],
        'bulkunlink' => ['key' => 'linkwise:bulkunlink:status', 'label' => 'bulk unlink'],
        'applyrule' => ['key' => 'linkwise:applyrule:status', 'label' => 'auto-link apply'],
        'urlchanger' => ['key' => 'linkwise:urlchanger:status', 'label' => 'URL changer'],
        'detailunlink' => ['key' => 'linkwise:detailunlink:status', 'label' => 'remove links'],
        'inboundinsert' => ['key' => 'linkwise:inboundinsert:status', 'label' => 'add inbound links'],
        'outboundinsert' => ['key' => 'linkwise:outboundinsert:status', 'label' => 'add outbound links'],
    ];

    /**
     * Phases that count as "still running" (mutually exclusive).
     *
     * Adding a new phase to a command? Add it here too — without this
     * registration, activeJob() returns null while the command is mid-
     * flight, snapshot() sees no active work, and other endpoints think
     * they can start in parallel. That was exactly the
     * `'checking'`-not-listed bug: while the broken-link check ran, the
     * global progress banner stayed empty AND bulk-unlink could be
     * dispatched alongside it.
     *
     * Keep aligned with the literal `'phase' => '...'` strings used in
     * each Command class.
     */
    protected const ACTIVE_PHASES = [
        'starting', 'running', 'indexing', 'suggestions', 'saving', 'checking',
    ];

    /**
     * Return the currently-active job (if any).
     *
     * @return array{name: string, label: string, status: array}|null
     */
    public static function activeJob(?string $exceptName = null): ?array
    {
        foreach (self::JOBS as $name => $meta) {
            if ($exceptName !== null && $name === $exceptName) {
                continue;
            }

            $status = Cache::get($meta['key']);
            if (! is_array($status)) {
                continue;
            }

            $phase = $status['phase'] ?? '';
            if (in_array($phase, self::ACTIVE_PHASES, true)) {
                return [
                    'name' => $name,
                    'label' => $meta['label'],
                    'status' => $status,
                ];
            }
        }

        return null;
    }

    /**
     * Build a JSON 409 response payload for a busy job.
     *
     * Surfaces the active job's owner ("started by Anna") when available, so
     * editors who hit a 409 know it's a colleague's run, not a stuck job.
     *
     * @param  array{name: string, label: string, status: array}  $active
     */
    public static function busyResponseData(array $active): array
    {
        $startedBy = $active['status']['started_by'] ?? null;
        $startedById = $active['status']['started_by_id'] ?? null;
        $currentUserId = auth()->user()?->id();

        // Skip "started by NAME" when the current user IS the owner — they
        // already know they triggered it. Frontend would otherwise show
        // "started by Artur" to Artur which is just noise.
        $byClause = ($startedBy && $startedById !== $currentUserId)
            ? ' — started by '.$startedBy
            : '';

        return [
            'error' => 'busy',
            'active_job' => $active['name'],
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'message' => 'Another bulk operation is running ('.$active['label'].$byClause.'). Wait for it to finish before starting a new one.',
        ];
    }

    /**
     * Phases that are TERMINAL — operation is finished, no further status
     * updates expected. Used by the crash-guard to decide whether shutdown
     * happened mid-run (still 'running') or after a clean termination.
     */
    protected const TERMINAL_PHASES = ['done', 'cancelled', 'error'];

    /**
     * Register a shutdown-time guard that flips the status to 'error' if the
     * process dies before reaching a terminal phase. Without this, segfault /
     * OOM / kill -9 leaves the JobLock hanging on 'running' for the full
     * cache TTL — the user sees a stuck banner with no way out except cache
     * flush.
     *
     * Call from the start of a long-running command's handle() method.
     *
     * @param  string  $statusKey   Cache key holding the job status
     * @param  string  $payloadKey  Cache key holding the job input (cleared on crash)
     */
    public static function registerCrashGuard(string $statusKey, string $payloadKey): void
    {
        register_shutdown_function(function () use ($statusKey, $payloadKey) {
            $current = Cache::get($statusKey);
            if (! is_array($current)) {
                return; // Nothing to clean up.
            }
            $phase = $current['phase'] ?? '';
            if (in_array($phase, self::TERMINAL_PHASES, true)) {
                return; // Clean exit — no fixup needed.
            }
            // We ended up here without ever flipping to a terminal phase.
            // The PHP process died unexpectedly. Mark the job as errored so
            // the frontend stops showing a stuck banner.
            Cache::put($statusKey, [
                'phase' => 'error',
                'message' => 'Background process terminated unexpectedly. Please retry.',
                'heartbeat' => time(),
            ], 300);
            Cache::forget($payloadKey);
        });
    }

    /**
     * Force-clear all heavy-job state. Used by the "stuck operation" recovery
     * UI when the user wants to reset after a crash that the shutdown guard
     * somehow missed (e.g. server restarted before shutdown_function fired).
     */
    public static function forceClear(string $jobName): void
    {
        if (! isset(self::JOBS[$jobName])) {
            return;
        }
        $statusKey = self::JOBS[$jobName]['key'];
        $payloadKey = str_replace(':status', ':payload', $statusKey);
        $cancelKey = str_replace(':status', ':cancel', $statusKey);
        Cache::forget($statusKey);
        Cache::forget($payloadKey);
        Cache::forget($cancelKey);
    }

    /**
     * Snapshot the most relevant heavy-job status for the unified frontend
     * banner. Prefers an actively-running job; falls back to the most-recent
     * terminal phase (done/cancelled/error) so the frontend can fire a single
     * completion toast and clear the banner. Returns null when nothing is
     * known on any job.
     *
     * @return array{name: string, label: string, status: array, terminal: bool}|null
     */
    public static function snapshot(): ?array
    {
        // Active wins — that's what the user is currently waiting on.
        if ($active = self::activeJob()) {
            return [
                'name' => $active['name'],
                'label' => $active['label'],
                'status' => $active['status'],
                'terminal' => false,
            ];
        }

        // Otherwise surface the most-recent terminal status so the poller can
        // dedup-toast it once.
        $latestTerminal = null;
        foreach (self::JOBS as $name => $meta) {
            $status = Cache::get($meta['key']);
            if (! is_array($status)) {
                continue;
            }
            $phase = $status['phase'] ?? '';
            if (! in_array($phase, ['done', 'cancelled', 'error'], true)) {
                continue;
            }
            $latestTerminal = [
                'name' => $name,
                'label' => $meta['label'],
                'status' => $status,
                'terminal' => true,
            ];
            // First found wins; ordering of self::JOBS is the priority.
            break;
        }

        return $latestTerminal;
    }
}
