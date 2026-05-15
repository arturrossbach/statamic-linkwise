import { describe, it, expect } from 'vitest';
import { buildCompletionSignature, isCompletionStale } from '@/services/bulkSignature.js';

/**
 * Characterisation tests for the bulk-completion signature + stale-detection
 * helpers extracted from LinkwiseLayout.vue::pollBulkStatusOnce.
 *
 * Sprint 5 PR 3a-prep — pure-function test net. Per advisor pre-PR-review:
 * "Pin-Tests für Signature-Truth-Table — alle 6 Kinds + Regression-Cases".
 *
 * Every test case in `describe('regression cases')` corresponds to a real
 * production bug whose root cause was a too-narrow signature. The test
 * pins the WORKING form so a future refactor that drops a heartbeat /
 * source_mode / total_rules differentiator fails loudly.
 */
describe('buildCompletionSignature', () => {
    const baseTerminalStatus = {
        phase: 'done',
        current: 5,
        total: 5,
        message: '',
        extra: {},
    };

    // ── Per-kind shape pins ────────────────────────────────────────────

    describe('applyrule kind', () => {
        it('encodes total_rules + total_links_added into signature', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'applyrule',
                extra: { total_rules: 3, total_links_added: 42 },
            });
            expect(sig).toBe('applyrule:done:5:5::r3:la42');
        });

        it('falls back from total_links_added to links_added (single-rule shape)', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'applyrule',
                extra: { links_added: 7 },
            });
            expect(sig).toBe('applyrule:done:5:5::r0:la7');
        });
    });

    describe('urlchanger kind', () => {
        it('encodes action + succeeded + skipped', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'urlchanger',
                extra: { action: 'replace', succeeded: 10, skipped: 2 },
            });
            expect(sig).toBe('urlchanger:done:5:5::areplace:s10:sk2');
        });
    });

    describe('detailunlink kind', () => {
        it('encodes source_mode + counts + heartbeat', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'detailunlink',
                extra: { source_mode: 'outbound', succeeded: 3, skipped: 0, heartbeat: 1700000000 },
            });
            expect(sig).toBe('detailunlink:done:5:5::moutbound:s3:sk0:hb1700000000');
        });

        it('falls back to started_by_id when heartbeat missing', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'detailunlink',
                extra: { source_mode: 'inbound', succeeded: 1, skipped: 0, started_by_id: 'user-42' },
            });
            expect(sig).toBe('detailunlink:done:5:5::minbound:s1:sk0:hbuser-42');
        });
    });

    describe('bulkunlink kind', () => {
        it('encodes succeeded + skipped + heartbeat', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'bulkunlink',
                extra: { succeeded: 12, skipped: 3, heartbeat: 1700000001 },
            });
            expect(sig).toBe('bulkunlink:done:5:5::s12:sk3:hb1700000001');
        });
    });

    describe('outboundinsert + inboundinsert kinds', () => {
        it('outboundinsert encodes counts + heartbeat', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'outboundinsert',
                extra: { succeeded: 4, skipped: 1, heartbeat: 1700000002 },
            });
            expect(sig).toBe('outboundinsert:done:5:5::s4:sk1:hb1700000002');
        });

        it('inboundinsert uses the same shape as outboundinsert', () => {
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'inboundinsert',
                extra: { succeeded: 4, skipped: 1, heartbeat: 1700000002 },
            });
            expect(sig).toBe('inboundinsert:done:5:5::s4:sk1:hb1700000002');
        });
    });

    describe('unknown kinds (safety property)', () => {
        it('produces base signature without kind-specific extras', () => {
            // 'scan' has no kind-specific branch — terminal cache uniqueness
            // is achieved by phase + current + total. If a regression
            // accidentally adds a 'scan' branch, this test pins the
            // baseline shape.
            const sig = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'scan',
                extra: { whatever: 'value' },
            });
            expect(sig).toBe('scan:done:5:5:');
        });

        it('handles missing extra object (no kind branch, no crash)', () => {
            const sig = buildCompletionSignature({
                kind: 'check',
                phase: 'done',
                current: 100,
                total: 100,
            });
            expect(sig).toBe('check:done:100:100:');
        });
    });

    // ── Regression cases — each pinned to a real production bug ────────

    describe('regression cases', () => {
        // 2026-05-10: outbound/inbound back-to-back identical-outcome bulks
        // produced same signature → second toast/banner dedup-suppressed.
        // Fix: include heartbeat. Pin: two consecutive identical-numbers
        // runs with different heartbeats must produce different signatures.
        it('outbound/inbound: identical succeeded+skipped with different heartbeats yields different signatures', () => {
            const runA = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'outboundinsert',
                extra: { succeeded: 0, skipped: 1, heartbeat: 1700000100 },
            });
            const runB = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'outboundinsert',
                extra: { succeeded: 0, skipped: 1, heartbeat: 1700000200 },
            });
            expect(runA).not.toBe(runB);
        });

        // 2026-05-11: detailunlink "5 removed / 0 skipped" twice in a row →
        // second persistent banner missing. Fix: heartbeat differentiator.
        it('detailunlink: identical outcome with different heartbeats yields different signatures', () => {
            const runA = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'detailunlink',
                extra: { source_mode: 'outbound', succeeded: 5, skipped: 0, heartbeat: 1700000300 },
            });
            const runB = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'detailunlink',
                extra: { source_mode: 'outbound', succeeded: 5, skipped: 0, heartbeat: 1700000400 },
            });
            expect(runA).not.toBe(runB);
        });

        // multi-rule applyrule ends with current=total=0 on top-level (counters
        // reset between rules). Without kind-specific extras every multi-rule
        // run got `applyrule:done:0:0:` → dedup blocked every run after the
        // first. Pin: two multi-rule runs with different total_links_added
        // must produce different signatures even when current+total are 0.
        it('applyrule multi-rule: zero counters with different total_links_added still differentiates', () => {
            const runA = buildCompletionSignature({
                kind: 'applyrule',
                phase: 'done',
                current: 0,
                total: 0,
                extra: { total_rules: 5, total_links_added: 10 },
            });
            const runB = buildCompletionSignature({
                kind: 'applyrule',
                phase: 'done',
                current: 0,
                total: 0,
                extra: { total_rules: 5, total_links_added: 20 },
            });
            expect(runA).not.toBe(runB);
            expect(runA).toBe('applyrule:done:0:0::r5:la10');
            expect(runB).toBe('applyrule:done:0:0::r5:la20');
        });

        // Different source_mode but identical numbers must NOT collapse.
        // E.g. user runs detailunlink in outbound mode, then in inbound
        // mode, same numbers — both must fire their banner.
        it('detailunlink: different source_mode with identical counts yields different signatures', () => {
            const out = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'detailunlink',
                extra: { source_mode: 'outbound', succeeded: 1, skipped: 0, heartbeat: 1700000500 },
            });
            const inb = buildCompletionSignature({
                ...baseTerminalStatus,
                kind: 'detailunlink',
                extra: { source_mode: 'inbound', succeeded: 1, skipped: 0, heartbeat: 1700000500 },
            });
            expect(out).not.toBe(inb);
        });
    });
});

describe('isCompletionStale', () => {
    it('returns true when heartbeat is missing (legacy pre-heartbeat cache)', () => {
        // 2026-05-11 bug: legacy scan terminal cache without heartbeat fired
        // a fresh-looking toast on first poll. Fix: missing heartbeat = stale.
        expect(isCompletionStale(0, 1700000000)).toBe(true);
        expect(isCompletionStale(null, 1700000000)).toBe(true);
        expect(isCompletionStale(undefined, 1700000000)).toBe(true);
    });

    it('returns false for fresh completion (heartbeat ≤ 60s old)', () => {
        const now = 1700000000;
        expect(isCompletionStale(now - 0, now)).toBe(false);
        expect(isCompletionStale(now - 30, now)).toBe(false);
        expect(isCompletionStale(now - 60, now)).toBe(false);
    });

    it('returns true for completion older than 60s', () => {
        const now = 1700000000;
        expect(isCompletionStale(now - 61, now)).toBe(true);
        expect(isCompletionStale(now - 600, now)).toBe(true);
    });

    it('absorbs small clock drift (future heartbeat = not stale)', () => {
        // Server clock slightly ahead of client → heartbeat > nowSec.
        // Math.max(0, ...) clamps the age to 0 so we treat as fresh.
        const now = 1700000000;
        expect(isCompletionStale(now + 5, now)).toBe(false);
    });
});
