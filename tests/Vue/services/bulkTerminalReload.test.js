import { describe, it, expect } from 'vitest';
import { pickTerminalReload } from '@/services/bulkTerminalReload.js';

/**
 * Characterisation tests for the terminal-bulk reload decision helper
 * extracted from LinkwiseLayout.vue::pollBulkStatusOnce.
 *
 * Sprint 5 PR 3b-prep — pure-function test net.
 *
 * Three concerns the truth-table encodes (each = one inline branch):
 *
 *   1. scan-done    → 'full'    (window.location.reload)
 *   2. check-done   → 'full'    (window.location.reload)
 *   3. outbound/inbound-done → 'partial' (Inertia reload)
 *
 * Plus three safety properties:
 *
 *   - `seenRunning[kind]` guard prevents loop-reload from stale cache
 *   - Non-'done' phases (cancelled, error, ...) never reload
 *   - Unknown kinds never reload (would-be infinite loop if they did)
 */
describe('pickTerminalReload', () => {
    // Full seenRunning baseline matching LinkwiseLayout's data() default.
    const allSeen = {
        scan: true, check: true, bulkunlink: true, applyrule: true,
        urlchanger: true, detailunlink: true, inboundinsert: true, outboundinsert: true,
    };
    const noneSeen = {
        scan: false, check: false, bulkunlink: false, applyrule: false,
        urlchanger: false, detailunlink: false, inboundinsert: false, outboundinsert: false,
    };

    // ── Per-kind decision pins ─────────────────────────────────────────

    describe('scan-done → full reload', () => {
        it("returns 'full' when scan done + seenRunning.scan=true", () => {
            expect(pickTerminalReload({ kind: 'scan', phase: 'done' }, allSeen)).toBe('full');
        });

        it("returns 'none' when seenRunning.scan=false (stale-cache guard)", () => {
            // Real-world scenario: user reloaded right after a scan
            // finished. Server cache still serves 'done' on first poll,
            // but THIS instance never observed scan running. Without
            // the guard we'd reload-loop forever.
            expect(pickTerminalReload({ kind: 'scan', phase: 'done' }, noneSeen)).toBe('none');
        });
    });

    describe('check-done → full reload', () => {
        it("returns 'full' when check done + seenRunning.check=true", () => {
            expect(pickTerminalReload({ kind: 'check', phase: 'done' }, allSeen)).toBe('full');
        });

        it("returns 'none' when seenRunning.check=false (stale-cache guard)", () => {
            expect(pickTerminalReload({ kind: 'check', phase: 'done' }, noneSeen)).toBe('none');
        });
    });

    describe('inboundinsert/outboundinsert-done → partial Inertia reload', () => {
        it("returns 'partial' for inboundinsert done + seenRunning=true", () => {
            expect(pickTerminalReload({ kind: 'inboundinsert', phase: 'done' }, allSeen)).toBe('partial');
        });

        it("returns 'partial' for outboundinsert done + seenRunning=true", () => {
            expect(pickTerminalReload({ kind: 'outboundinsert', phase: 'done' }, allSeen)).toBe('partial');
        });

        it("returns 'none' for inboundinsert when seenRunning=false", () => {
            expect(pickTerminalReload({ kind: 'inboundinsert', phase: 'done' }, noneSeen)).toBe('none');
        });

        it("returns 'none' for outboundinsert when seenRunning=false", () => {
            expect(pickTerminalReload({ kind: 'outboundinsert', phase: 'done' }, noneSeen)).toBe('none');
        });
    });

    // ── Kinds that DON'T reload ────────────────────────────────────────

    describe('non-reloading kinds', () => {
        const nonReloadKinds = ['applyrule', 'urlchanger', 'bulkunlink', 'detailunlink'];

        for (const kind of nonReloadKinds) {
            it(`returns 'none' for ${kind} done even when seenRunning=true`, () => {
                expect(pickTerminalReload({ kind, phase: 'done' }, allSeen)).toBe('none');
            });
        }
    });

    // ── Phase guard (only fire on 'done') ──────────────────────────────

    describe('non-done phases never reload', () => {
        const nonDonePhases = ['idle', 'starting', 'running', 'indexing', 'cancelled', 'error', 'finalizing'];

        for (const phase of nonDonePhases) {
            it(`returns 'none' for scan in '${phase}' phase`, () => {
                expect(pickTerminalReload({ kind: 'scan', phase }, allSeen)).toBe('none');
            });
        }
    });

    // ── Defensive cases ───────────────────────────────────────────────

    describe('defensive cases', () => {
        it("returns 'none' for unknown kind (safety: avoids invented reload loop)", () => {
            expect(pickTerminalReload({ kind: 'somenewthing', phase: 'done' }, allSeen)).toBe('none');
        });

        it("returns 'none' when seenRunning object is missing (no crash)", () => {
            // Edge case: pollBulkStatusOnce passes seenRunning by reference;
            // if caller ever forgets, optional-chaining keeps us safe.
            expect(pickTerminalReload({ kind: 'scan', phase: 'done' }, undefined)).toBe('none');
            expect(pickTerminalReload({ kind: 'scan', phase: 'done' }, null)).toBe('none');
        });

        it("returns 'none' when seenRunning is missing the specific key", () => {
            expect(pickTerminalReload({ kind: 'scan', phase: 'done' }, {})).toBe('none');
        });
    });
});
