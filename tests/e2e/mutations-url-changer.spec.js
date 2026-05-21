import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── URL Changer — Mutating Actions ────────────────────────────────────────────
// Search → Preview → Apply (replace OR unlink) is a heavy-bulk operation:
// detached artisan command with status polling. UI wiring covered here.

test.describe('@mutations URL Changer — Action wiring', () => {
    test('Search button fires preview endpoint', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/url-changer');
        await page.waitForLoadState('networkidle');

        let captured = null;
        await page.route('**/cp/linkwise/url-changer/preview', async (route) => {
            captured = await route.request().postDataJSON();
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ entries: [] }),
            });
        });

        // First text input is the search field
        const searchInput = page.locator('input').first();
        await searchInput.fill('https://example.com');

        // Trigger search via button or Enter
        const searchBtn = page.getByRole('button', { name: /Search|Find/i }).first();
        if (await searchBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
            await searchBtn.click({ force: true });
        } else {
            await searchInput.press('Enter');
        }

        await page.waitForTimeout(500);
        expect(captured, 'preview endpoint should have been called').not.toBeNull();
        expect(captured.search).toBe('https://example.com');
    });

    test('Apply button is disabled when nothing is ready', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/url-changer');
        await page.waitForLoadState('networkidle');

        // No preview run, no matches → Apply button should be either hidden or disabled
        const applyBtn = page.getByRole('button', { name: /Apply \d+ change/ });
        if (await applyBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
            await expect(applyBtn).toBeDisabled();
        }
    });
});
