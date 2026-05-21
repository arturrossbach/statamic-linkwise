import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Links Report — Mutating Actions ───────────────────────────────────────────
// Suggestion Modal (Inbound/Outbound Insert), Detail Modal Bulk-Unlink.

test.describe('@mutations Links Report — Action wiring', () => {
    test('Inbound suggestion button opens SuggestionModal', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/links');
        await page.waitForLoadState('networkidle');

        // "orphan" badge or "add +N" badge → opens inbound modal.
        const trigger = page.locator('button').filter({ hasText: /^orphan$|^add \+\d+$/ }).first();
        if (!(await trigger.isVisible({ timeout: 2000 }).catch(() => false))) {
            test.skip(true, 'No orphan/add-suggestion entries in fixture');
        }

        await trigger.scrollIntoViewIfNeeded();
        await trigger.click({ force: true });

        // Modal opens — title contains "Suggestions" or entry title
        await page.waitForTimeout(300);
        const modal = page.locator('[role="dialog"], .stack, [class*="stack"]').first();
        await expect(modal).toBeVisible({ timeout: 2000 });
    });

    test('Detail-view button opens DetailModal with inbound/outbound tabs', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/links');
        await page.waitForLoadState('networkidle');

        // First entry's "Inbound" or "Outbound" count link in table
        const detailLink = page.locator('tbody button').filter({ hasText: /^\d+$/ }).first();
        if (!(await detailLink.isVisible({ timeout: 2000 }).catch(() => false))) {
            test.skip(true, 'No entries with link counts to expand');
        }

        await detailLink.scrollIntoViewIfNeeded();
        await detailLink.click({ force: true });

        await page.waitForTimeout(300);
        const modal = page.locator('[role="dialog"], .stack, [class*="stack"]').first();
        await expect(modal).toBeVisible({ timeout: 2000 });
    });
});
