<?php

namespace Arturrossbach\Linkwise\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Single source-of-truth for bulk-job status-cache writes.
 *
 * REV-OB-01 (2026-05-13): LinkInsertCommand had 7 near-identical
 * Cache::put($statusKey, [...]) sites with 60–80% field duplication.
 * Adding a new status field required editing all 7 — Bug 2026-05-11
 * (root-level heartbeat for dedup-signature) was this exact failure
 * mode, where the field was added to some sites but not others and the
 * frontend toast got dedup-suppressed. The file's own comments
 * documented the miss.
 *
 * This writer collapses the cascade. Constructor takes the context
 * fields that stay constant across all phases (statusKey, source_mode,
 * entry_title, started_by, started_by_id). Methods per terminal phase
 * (running / cancelled / indexing / done) write the cache entry with
 * the right shape + correct TTL + auto-heartbeat + auto-extra-mirror
 * on done.
 *
 * Used by LinkInsertCommand. BulkUnlinkCommand / DetailUnlinkCommand /
 * UrlChangerApplyCommand / ApplyRuleCommand are candidate consumers
 * for follow-up REV-BJ-05 (cross-command status-cache consolidation).
 */
class BulkStatusWriter
{
    private const TTL_RUNNING = 600;
    private const TTL_TERMINAL = 300;

    /**
     * @param  string  $statusKey   Cache key holding the job's status JSON
     * @param  array<string, mixed>  $context  Fields that stay constant across
     *                                         the run (source_mode, entry_title,
     *                                         started_by, started_by_id …).
     */
    public function __construct(
        protected string $statusKey,
        protected array $context = [],
    ) {}

    /**
     * Update the context dictionary at runtime (e.g. as totals change or
     * additional metadata becomes known mid-run). Keys not passed are kept.
     */
    public function mergeContext(array $additions): void
    {
        $this->context = array_merge($this->context, $additions);
    }

    /**
     * Write a running-phase status with progress + counters.
     * Heartbeat is auto-set so the frontend's "is it alive" check works.
     */
    public function running(int $current, int $total, int $succeeded = 0, int $skipped = 0): void
    {
        $payload = array_merge($this->context, [
            'phase' => 'running',
            'current' => $current,
            'total' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'heartbeat' => time(),
        ]);
        Cache::put($this->statusKey, $payload, self::TTL_RUNNING);
    }

    /**
     * Write a cancelled-phase status (user clicked Cancel). Captures the
     * progress reached + the error tally for the toast.
     */
    public function cancelled(int $current, int $total, int $succeeded, int $skipped, array $errors = []): void
    {
        $payload = array_merge($this->context, [
            'phase' => 'cancelled',
            'current' => $current,
            'total' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
        Cache::put($this->statusKey, $payload, self::TTL_TERMINAL);
    }

    /**
     * Write the indexing-phase status — bulk inserts done, index rebuild
     * in progress. Banner reads "Finalizing index…" instead of stuck N/N.
     */
    public function indexing(int $total, int $succeeded, int $skipped): void
    {
        $payload = array_merge($this->context, [
            'phase' => 'indexing',
            'current' => $total,
            'total' => $total,
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'heartbeat' => time(),
        ]);
        Cache::put($this->statusKey, $payload, self::TTL_RUNNING);
    }

    /**
     * Write the done-phase status. Mirrors the progress fields into an
     * 'extra' block — LinkwiseLayout's completion-toast reads from there
     * via $status.extra || {}; without the mirror, back-to-back identical-
     * outcome bulks dedup-suppressed each other (Bug 2026-05-10/11).
     */
    public function done(int $total, int $succeeded, int $skipped, array $errors = []): void
    {
        $now = time();
        $progress = [
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
        $extra = array_merge($this->context, $progress, ['heartbeat' => $now]);

        $payload = array_merge($this->context, $progress, [
            'phase' => 'done',
            'current' => $total,
            'total' => $total,
            // Root-level heartbeat: bulkStatus controller maps the whole
            // cache to the frontend `extra` field, so the frontend dedup-
            // signature's `tExtra.heartbeat` reads from HERE. Without this,
            // back-to-back identical-outcome bulks produced the same
            // signature and the second toast/banner got dedup-suppressed.
            // Bug 2026-05-11.
            'heartbeat' => $now,
            'extra' => $extra,
        ]);
        Cache::put($this->statusKey, $payload, self::TTL_TERMINAL);
    }
}
