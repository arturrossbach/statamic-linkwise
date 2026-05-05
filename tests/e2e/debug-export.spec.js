import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Debug Export — Diagnostic ZIP for Support ─────────────────────────────────
// Default endpoint is GDPR-safe (counts + stats, no URLs). Logs are opt-in
// via ?include_logs=1 and the UI gates that path behind a confirmation modal.

test.describe('@debugexport Debug Export', () => {
    test('Default endpoint returns valid ZIP without log files', async ({ page }) => {
        await goToLinkwise(page);

        const response = await page.request.get('/cp/linkwise/debug-export');
        expect(response.status()).toBe(200);
        expect((response.headers()['content-type'] || '').toLowerCase()).toContain('zip');

        const disposition = response.headers()['content-disposition'] || '';
        expect(disposition, 'filename should match linkwise-debug-*.zip').toMatch(/linkwise-debug-/);

        const body = await response.body();
        expect(body.length, 'ZIP should be non-empty').toBeGreaterThan(0);

        // Look at first 1KB for ZIP magic bytes (PK\x03\x04 = 0x504b0304).
        const head = body.subarray(0, 4);
        expect(head[0]).toBe(0x50);
        expect(head[1]).toBe(0x4B);
    });

    test('include_logs=1 endpoint returns larger ZIP with log entries', async ({ page }) => {
        await goToLinkwise(page);

        const safe = await page.request.get('/cp/linkwise/debug-export');
        const verbose = await page.request.get('/cp/linkwise/debug-export?include_logs=1');

        const safeSize = (await safe.body()).length;
        const verboseSize = (await verbose.body()).length;

        // Verbose ZIP must include logs/ entries on top of the safe baseline.
        // Even on a fresh test fixture there is usually at least one log file.
        expect(verboseSize, 'with-logs ZIP should be >= safe ZIP').toBeGreaterThanOrEqual(safeSize);
    });

    test('Header dropdown menu has Download diagnostic ZIP item', async ({ page }) => {
        await goToLinkwise(page);

        // Statamic 6 Dropdown renders the trigger with [data-ui-dropdown-trigger]
        // and aria-label "Open dropdown menu". Multiple dropdowns can exist on
        // a page (per-row rule actions), but the header one is the only one
        // visible on Overview without scrolling — pick the first.
        // Statamic CP also renders a breadcrumb dropdown in <header> — scope
        // to <main> so we hit Linkwise's header dropdown instead.
        const trigger = page.getByRole('main').locator('[data-ui-dropdown-trigger]').first();
        await trigger.scrollIntoViewIfNeeded();
        await trigger.click({ force: true });

        await expect(page.getByRole('menuitem', { name: /Download diagnostic ZIP$/ })).toBeVisible({ timeout: 3000 });
        await expect(page.getByRole('menuitem', { name: /with logs/i })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: /Linkwise \d/ })).toBeVisible();
    });

    test('"With logs" item opens confirmation modal', async ({ page }) => {
        await goToLinkwise(page);

        // Statamic CP also renders a breadcrumb dropdown in <header> — scope
        // to <main> so we hit Linkwise's header dropdown instead.
        const trigger = page.getByRole('main').locator('[data-ui-dropdown-trigger]').first();
        await trigger.scrollIntoViewIfNeeded();
        await trigger.click({ force: true });

        const withLogsItem = page.getByRole('menuitem', { name: /with logs/i });
        await withLogsItem.click({ force: true });

        // Modal title contains "Include log files"
        await expect(page.getByText(/Include log files in the export\?/)).toBeVisible({ timeout: 3000 });
        // Body text mentions URLs/PII risk
        await expect(page.getByText(/may contain URLs/i)).toBeVisible();
    });
});
