import { describe, it, expect } from 'vitest';
import {
    truncateUrl,
    formatAutoApply,
    normalizeAutoApply,
    formatExactDate,
    wouldLinkForRule,
} from '@/utils/ruleFormatting.js';

/**
 * Unit pins for resources/js/utils/ruleFormatting.js — the pure-helper
 * extract from AutoLinkingTab.vue (Sprint 5 PR 2d-prep). Pin the contract
 * so the upcoming RuleListTable sub-component can rely on these without
 * a method-prop bridge.
 */

describe('truncateUrl', () => {
    it('passes short URLs through unchanged', () => {
        expect(truncateUrl('https://example.com')).toBe('https://example.com');
    });

    it('cuts URLs longer than 50 chars at 47 + ellipsis', () => {
        const long = 'https://example.com/very-long-path-segment/that-keeps-going/and-going';
        const out = truncateUrl(long);
        expect(out.length).toBe(50);
        expect(out.endsWith('...')).toBe(true);
        expect(out.startsWith('https://example.com')).toBe(true);
    });

    it('boundary: 50-char URL stays untouched (50 not > 50)', () => {
        const url = 'a'.repeat(50);
        expect(truncateUrl(url)).toBe(url);
    });

    it('boundary: 51-char URL gets truncated', () => {
        const url = 'a'.repeat(51);
        expect(truncateUrl(url).length).toBe(50);
    });
});

describe('formatAutoApply', () => {
    it('maps each tri-state value to its display label', () => {
        expect(formatAutoApply('always')).toBe('Always');
        expect(formatAutoApply('never')).toBe('Never');
        expect(formatAutoApply('follow_global')).toBe('Follow global');
    });

    it('falls back to "Follow global" for unknown / undefined values', () => {
        expect(formatAutoApply(undefined)).toBe('Follow global');
        expect(formatAutoApply(null)).toBe('Follow global');
        expect(formatAutoApply('garbage')).toBe('Follow global');
    });
});

describe('normalizeAutoApply', () => {
    it('preserves the three valid tri-state strings', () => {
        expect(normalizeAutoApply('always')).toBe('always');
        expect(normalizeAutoApply('never')).toBe('never');
        expect(normalizeAutoApply('follow_global')).toBe('follow_global');
    });

    it('migrates legacy bool true → follow_global', () => {
        expect(normalizeAutoApply(true)).toBe('follow_global');
    });

    it('migrates legacy bool false → never', () => {
        expect(normalizeAutoApply(false)).toBe('never');
    });

    it('falls back to follow_global for unknown values', () => {
        expect(normalizeAutoApply(undefined)).toBe('follow_global');
        expect(normalizeAutoApply(null)).toBe('follow_global');
        expect(normalizeAutoApply('garbage')).toBe('follow_global');
        expect(normalizeAutoApply(42)).toBe('follow_global');
    });
});

describe('formatExactDate', () => {
    it('returns empty string for null/undefined/empty', () => {
        expect(formatExactDate(null)).toBe('');
        expect(formatExactDate(undefined)).toBe('');
        expect(formatExactDate('')).toBe('');
    });

    it('parses a valid ISO timestamp to a locale-formatted string', () => {
        const out = formatExactDate('2026-05-15T12:00:00Z');
        // Format depends on locale of the test environment, but must
        // mention 2026 — that's the part the user cares about reading.
        expect(out).toContain('2026');
        expect(out.length).toBeGreaterThan(0);
    });

    it('never throws — returns input on parse failure', () => {
        // toLocaleString on Invalid Date returns "Invalid Date", not a
        // throw — the try/catch is defensive belt-and-braces. Pin the
        // observable: no throw.
        expect(() => formatExactDate('not-a-date')).not.toThrow();
    });
});

describe('wouldLinkForRule', () => {
    it('subtracts already-linked, linked-elsewhere, and not-insertable from match_count', () => {
        const rule = {
            match_count: 10,
            linked_count: 2,
            linked_elsewhere_count: 1,
            not_insertable_count: 1,
        };
        expect(wouldLinkForRule(rule)).toBe(6);
    });

    it('clamps to zero when the subtraction would go negative (stale-index defence)', () => {
        const rule = {
            match_count: 3,
            linked_count: 5,
            linked_elsewhere_count: 0,
            not_insertable_count: 0,
        };
        expect(wouldLinkForRule(rule)).toBe(0);
    });

    it('treats missing fields as zero', () => {
        expect(wouldLinkForRule({ match_count: 5 })).toBe(5);
        expect(wouldLinkForRule({})).toBe(0);
    });
});
