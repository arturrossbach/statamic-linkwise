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
     * Scope constants for conflict detection (Sprint 6 REV-BJ-03).
     *
     *   - GLOBAL: rebuilds or mutates the entire index. Conflicts with
     *     ANY other active job, in either direction. Used for scan +
     *     broken-link-check.
     *   - PER_ENTRY: mutates a known set of entries. Conflicts with other
     *     PER_ENTRY jobs only when entry-sets intersect. Conflicts with
     *     GLOBAL jobs always (index-rebuild semaphore).
     */
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_PER_ENTRY = 'per_entry';

    /**
     * Status cache keys + human labels + lock-scope for each background job.
     *
     * `scope` was added in Sprint 6 REV-BJ-03 — domain-decided 2026-05-13:
     * Per-entry write-lock + global index-rebuild semaphore. Editors on
     * disjoint entries no longer block each other; the index-touching
     * operations (scan/check) still take a global exclusive lock.
     */
    public const JOBS = [
        'scan' => ['key' => 'linkwise:scan:status', 'label' => 'content scan', 'scope' => self::SCOPE_GLOBAL],
        'check' => ['key' => 'linkwise:check:status', 'label' => 'broken-link check', 'scope' => self::SCOPE_GLOBAL],
        'bulkunlink' => ['key' => 'linkwise:bulkunlink:status', 'label' => 'bulk unlink', 'scope' => self::SCOPE_PER_ENTRY],
        'applyrule' => ['key' => 'linkwise:applyrule:status', 'label' => 'auto-link apply', 'scope' => self::SCOPE_PER_ENTRY],
        'urlchanger' => ['key' => 'linkwise:urlchanger:status', 'label' => 'URL changer', 'scope' => self::SCOPE_PER_ENTRY],
        'detailunlink' => ['key' => 'linkwise:detailunlink:status', 'label' => 'remove links', 'scope' => self::SCOPE_PER_ENTRY],
        'inboundinsert' => ['key' => 'linkwise:inboundinsert:status', 'label' => 'add inbound links', 'scope' => self::SCOPE_PER_ENTRY],
        'outboundinsert' => ['key' => 'linkwise:outboundinsert:status', 'label' => 'add outbound links', 'scope' => self::SCOPE_PER_ENTRY],
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
     * Pre-Sprint-6 default-behaviour preserved: scans the JOBS array,
     * returns the first job whose status is in an ACTIVE_PHASES state.
     * Callers that want scope-aware conflict detection (per-entry locks
     * on disjoint entry-sets don't block each other) should use
     * `activeJobConflicting()` instead.
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
     * Scope-aware variant of activeJob() — returns the first active job
     * that would CONFLICT with a new operation of `$requestingName` on
     * `$requestingEntryIds`. Conflict rules (Sprint 6 REV-BJ-03):
     *
     *   - If either side is GLOBAL scope (scan/check) → always conflicts.
     *   - If both are PER_ENTRY scope:
     *       - Conflict when the running job's entry-set INTERSECTS the
     *         requesting set. Editors on disjoint entries don't block.
     *       - When the running job stored no entry-set (legacy command
     *         or starting-phase before the IDs were written) → treat as
     *         conflict to be safe.
     *       - When the requesting side passes null entry-ids → treat as
     *         "scope is per_entry but ids not yet known" → conflict for
     *         safety (callers should pass the real set when possible).
     *
     * Returns null when no conflict exists — caller can proceed.
     *
     * @param  string  $requestingName  job kind being requested (must be in JOBS)
     * @param  list<string>|null  $requestingEntryIds  entries the new job will touch
     * @return array{name: string, label: string, status: array}|null
     */
    public static function activeJobConflicting(string $requestingName, ?array $requestingEntryIds = null): ?array
    {
        $requestingScope = self::JOBS[$requestingName]['scope'] ?? self::SCOPE_GLOBAL;

        foreach (self::JOBS as $name => $meta) {
            if ($name === $requestingName) {
                // A job kind never blocks a NEW dispatch of the same kind
                // — that path is governed by HTTP-409 in the per-kind
                // controller's own pre-flight check, not here.
                continue;
            }

            $status = Cache::get($meta['key']);
            if (! is_array($status)) {
                continue;
            }

            $phase = $status['phase'] ?? '';
            if (! in_array($phase, self::ACTIVE_PHASES, true)) {
                continue;
            }

            $activeScope = $meta['scope'] ?? self::SCOPE_GLOBAL;
            // Prefer the side-cache (`linkwise:{kind}:entry_ids`) — it's
            // written once at dispatch and survives every subsequent
            // status-rewrite the running command does. Fall back to
            // status.extra.entry_ids for commands that haven't been
            // migrated to the side-cache yet (BC during wire-in).
            $activeEntryIds = self::entryIdsForJob($name) ?? self::entryIdsOf($status);
            if (! self::conflicts($activeScope, $activeEntryIds, $requestingScope, $requestingEntryIds)) {
                continue;
            }

            return [
                'name' => $name,
                'label' => $meta['label'],
                'status' => $status,
            ];
        }

        return null;
    }

    /**
     * Pure conflict-detection. Lifted out so the truth-table is testable
     * without bootstrapping the cache facade. Sprint 6 REV-BJ-03.
     *
     * @param  string  $activeScope  SCOPE_GLOBAL | SCOPE_PER_ENTRY
     * @param  list<string>|null  $activeEntryIds  entry-set the running job mutates (null = unknown)
     * @param  string  $requestingScope  SCOPE_GLOBAL | SCOPE_PER_ENTRY
     * @param  list<string>|null  $requestingEntryIds  entry-set the new job would mutate
     */
    public static function conflicts(
        string $activeScope,
        ?array $activeEntryIds,
        string $requestingScope,
        ?array $requestingEntryIds,
    ): bool {
        // Any global participation → always conflict. Index-rebuild
        // semaphore: scan/check take exclusive lock against everything.
        if ($activeScope === self::SCOPE_GLOBAL || $requestingScope === self::SCOPE_GLOBAL) {
            return true;
        }

        // Both per-entry. Without entry-id information on either side,
        // play safe — assume potential intersection.
        if ($activeEntryIds === null || $requestingEntryIds === null) {
            return true;
        }

        // Disjoint entry sets → no conflict. Two editors on different
        // posts can run their bulks in parallel.
        $intersection = array_intersect(
            array_map('strval', $activeEntryIds),
            array_map('strval', $requestingEntryIds),
        );

        return $intersection !== [];
    }

    /**
     * Cache key for a job's entry-id side-channel. Written once at dispatch
     * and survives every status-rewrite the running command does (the
     * command's repeated Cache::put on `:status` would otherwise erase it
     * if we stored entry-ids in the status payload).
     */
    public static function entryIdsKeyFor(string $jobName): string
    {
        return 'linkwise:'.$jobName.':entry_ids';
    }

    /**
     * Record the entry-set a per-entry job is about to touch. Called by
     * controllers at dispatch time (after validation + before
     * Cache::put(:status)). TTL matches the typical payload TTL so a
     * crashed command's entry-id set ages out the same way.
     *
     * Pass-through for GLOBAL-scope jobs is a no-op (they take exclusive
     * lock; entry-id intersection is irrelevant).
     *
     * @param  list<string>  $entryIds
     */
    public static function recordEntryIds(string $jobName, array $entryIds, int $ttlSec = 600): void
    {
        if (! isset(self::JOBS[$jobName])) {
            return;
        }
        if ((self::JOBS[$jobName]['scope'] ?? self::SCOPE_GLOBAL) !== self::SCOPE_PER_ENTRY) {
            return;
        }
        Cache::put(self::entryIdsKeyFor($jobName), array_values(array_map('strval', $entryIds)), $ttlSec);
    }

    /**
     * Read the entry-set a currently-running job is mutating from the
     * side-cache. Returns null when no set has been recorded — caller
     * treats null as "unknown → assume conflict" via `conflicts()`.
     *
     * @return list<string>|null
     */
    public static function entryIdsForJob(string $jobName): ?array
    {
        $raw = Cache::get(self::entryIdsKeyFor($jobName));
        if (! is_array($raw)) {
            return null;
        }
        $clean = [];
        foreach ($raw as $id) {
            if (is_string($id) || is_int($id)) {
                $clean[] = (string) $id;
            }
        }

        return $clean;
    }

    /**
     * Drop the entry-id side-cache for a job. Called by the crash-guard
     * and by terminal-phase writes so a finished job stops contributing
     * to conflict checks for the next bulk.
     */
    public static function forgetEntryIds(string $jobName): void
    {
        if (! isset(self::JOBS[$jobName])) {
            return;
        }
        Cache::forget(self::entryIdsKeyFor($jobName));
    }

    /**
     * Extract the entry-id set a running job is mutating from its status
     * cache. Commands write the set under `status.extra.entry_ids` when
     * they start (Sprint 6 REV-BJ-03 wire-in). Returns null when the
     * set hasn't been written yet — callers should treat null as "unknown,
     * assume conflict" via `conflicts()`.
     *
     * @return list<string>|null
     */
    public static function entryIdsOf(array $status): ?array
    {
        $extra = $status['extra'] ?? null;
        if (! is_array($extra)) {
            return null;
        }
        $ids = $extra['entry_ids'] ?? null;
        if (! is_array($ids)) {
            return null;
        }

        // Coerce to list<string> + drop non-scalar entries (defensive
        // against malformed cache state from old/dev runs).
        $clean = [];
        foreach ($ids as $id) {
            if (is_string($id) || is_int($id)) {
                $clean[] = (string) $id;
            }
        }

        return $clean;
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
        // Sprint 6 REV-BJ-03 — also drop the entry-id side-channel so a
        // force-clear doesn't leave the conflict-checker thinking the
        // (now-gone) job is still mutating those entries.
        self::forgetEntryIds($jobName);
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
