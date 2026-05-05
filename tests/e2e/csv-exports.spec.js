import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── CSV Exports — Smoke Tests ─────────────────────────────────────────────────
// Why this exists: a missing `['broken_links']` key in brokenLinksCsv shipped
// to dev with no test catching it — Export button returned ERR_INVALID_RESPONSE
// because PHP fataled mid-stream. Every CSV endpoint now gets a smoke test
// asserting (1) HTTP 200, (2) text/csv content type, (3) non-empty body with
// expected header columns. Future PHP fatals can no longer slip past CI.

const EXPORTS = [
    {
        name: 'Broken Links',
        url: '/cp/linkwise/broken-links/export',
        expectedHeaders: ['Entry Title', 'Broken URL', 'Status Label', 'First Detected'],
        filenamePrefix: 'linkwise-broken-links-',
    },
    {
        name: 'Domains',
        url: '/cp/linkwise/domains/export',
        expectedHeaders: ['Domain', 'Posts Count', 'Links Count', 'Attribute'],
        filenamePrefix: 'linkwise-domains-',
    },
    {
        name: 'Auto-Linking Rules',
        url: '/cp/linkwise/autolink/rules/export',
        expectedHeaders: ['Keyword', 'URL', 'Target Entry Title'],
        filenamePrefix: 'linkwise-autolink-rules-',
    },
];

test.describe('@csv CSV Exports — Smoke', () => {
    // We need an authenticated session. goToLinkwise navigates and ensures
    // auth state is loaded; then we can hit the export URLs directly with
    // page.request which inherits cookies.

    for (const exp of EXPORTS) {
        test(`${exp.name} export returns valid CSV`, async ({ page }) => {
            await goToLinkwise(page);

            const response = await page.request.get(exp.url);

            expect(response.status(), `${exp.name} HTTP status`).toBe(200);

            const contentType = response.headers()['content-type'] || '';
            expect(contentType.toLowerCase(), `${exp.name} content-type`).toContain('text/csv');

            // Filename header — confirms StreamedResponse went through
            const disposition = response.headers()['content-disposition'] || '';
            expect(disposition, `${exp.name} content-disposition`).toContain(exp.filenamePrefix);

            const body = await response.text();
            expect(body.length, `${exp.name} body must be non-empty`).toBeGreaterThan(0);

            // Strip BOM, parse first row, verify headers
            const cleaned = body.replace(/^﻿/, '');
            const firstLine = cleaned.split(/\r?\n/)[0];
            for (const header of exp.expectedHeaders) {
                expect(firstLine, `${exp.name} CSV must include "${header}" column`).toContain(header);
            }
        });
    }
});
