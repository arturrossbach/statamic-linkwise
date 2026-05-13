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
        $phase = $active['status']['phase'] ?? null;
        $current = isset($active['status']['current']) ? (int) $active['status']['current'] : null;
        $total = isset($active['status']['total']) ? (int) $active['status']['total'] : null;
        $currentUserId = auth()->user()?->id();

        return [
            'error' => 'busy',
            'active_job' => $active['name'],
            'started_by' => $startedBy,
            'started_by_id' => $startedById,
            'message' => self::buildBusyMessage(
                label: $active['label'],
                phase: is_string($phase) ? $phase : null,
                current: $current,
                total: $total,
                startedBy: is_string($startedBy) ? $startedBy : null,
                isOwner: $startedBy !== null && $startedById === $currentUserId,
            ),
        ];
    }

    /**
     * Build the user-facing 409-busy message.
     *
     * REV-BJ-03 (2026-05-13): the global JobLock is INTENTIONAL — index
     * integrity depends on serialized writes to the JSON index file.
     * Editors who hit a 409 need to know (1) this is normal not stuck,
     * (2) WHO else is editing, (3) WHAT they're doing and HOW FAR ALONG,
     * (4) WHAT to do (wait). Previously the message was "Another bulk
     * operation is running (LABEL). Wait..." — no progress, no phase,
     * no rationale.
     *
     * Pure-static + parameter-passed so it's unit-testable without
     * bootstrapping auth() / Cache.
     */
    public static function buildBusyMessage(
        string $label,
        ?string $phase,
        ?int $current,
        ?int $total,
        ?string $startedBy,
        bool $isOwner,
    ): string {
        // "started by NAME" — only when the current user is NOT the owner.
        // Showing the user their own name as the conflict source is noise.
        $byClause = ($startedBy !== null && ! $isOwner)
            ? ' — started by '.$startedBy
            : '';

        // Progress: only when both current and total are known and total > 0.
        $progressClause = '';
        if ($current !== null && $total !== null && $total > 0) {
            $progressClause = ' ('.$current.'/'.$total.')';
        }

        // Phase-specific verb + action guidance.
        switch ($phase) {
            case 'indexing':
                // Index-rebuild — cannot cancel, almost done.
                return "{$label}{$byClause} is finalizing (Index rebuild{$progressClause}). "
                    .'This step is short — wait for it to finish before starting a new bulk.';
            case 'starting':
                // Just dispatched — no progress yet.
                return "{$label}{$byClause} is starting. "
                    .'Wait for it to finish before starting a new bulk operation.';
            case 'cancelled':
                // Transient — should clear shortly.
                return "{$label}{$byClause} is cancelling. "
                    .'Wait a moment for it to release.';
            case 'running':
            default:
                return "{$label}{$byClause} is running{$progressClause}. "
                    .'Wait for it to finish before starting a new bulk operation '
                    .'(serialized by design to keep the link index consistent).';
        }
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
        // dedup-toast it once. Selection is by RECENCY (heartbeat),
        // not by JOBS-array order (Bug 2026-05-11): an old `scan` terminal
        // status sitting in cache used to shadow a freshly-completed
        // `detailunlink` simply because 'scan' came first in the array,
        // and the frontend never saw the detailunlink completion (no
        // banner, no warning toast for "already gone" outcomes).
        // Heartbeat lives at the cache root for every done-status that
        // ships post-fix; legacy statuses without heartbeat sort last
        // (deprioritised — they're already past their useful window).
        $latestTerminal = null;
        $latestHeartbeat = -1;
        foreach (self::JOBS as $name => $meta) {
            $status = Cache::get($meta['key']);
            if (! is_array($status)) {
                continue;
            }
            $phase = $status['phase'] ?? '';
            if (! in_array($phase, ['done', 'cancelled', 'error'], true)) {
                continue;
            }
            $heartbeat = (int) ($status['heartbeat'] ?? 0);
            if ($heartbeat > $latestHeartbeat) {
                $latestHeartbeat = $heartbeat;
                $latestTerminal = [
                    'name' => $name,
                    'label' => $meta['label'],
                    'status' => $status,
                    'terminal' => true,
                ];
            }
        }

        return $latestTerminal;
    }
}
