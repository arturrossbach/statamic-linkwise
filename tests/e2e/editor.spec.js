import { test, expect } from '@playwright/test';
import { goToLinkwise, clickTab, dismissOverlays } from './helpers.js';

test.describe('Entry Editor — Linkwise Sidebar', () => {
    test('Story: Agency opens an entry and sees link stats in sidebar', async ({ page }) => {
        await page.goto('/cp/collections/pages/entries/home');
        await page.waitForLoadState('networkidle');
        await dismissOverlays(page);

        await expect(page.locator('text=Linkwise').first()).toBeVisible({ timeout: 5000 });
        await expect(page.locator('text=Inbound:').or(page.locator('text=Inbound')).first()).toBeVisible({ timeout: 5000 });
    });

    test('Story: Agency opens an article and sees sidebar', async ({ page }) => {
        await page.goto('/cp/collections/articles');
        await page.waitForLoadState('networkidle');
        await dismissOverlays(page);

        const firstEntry = page.locator('.listing-row a, tbody tr a').first();
        if (await firstEntry.isVisible({ timeout: 3000 }).catch(() => false)) {
            await firstEntry.click();
            await page.waitForLoadState('networkidle');
            await dismissOverlays(page);

            const sidebar = page.locator('text=Linkwise').first();
            await expect(sidebar).toBeVisible({ timeout: 5000 });
        }
    });
});

test.describe('Inbound Suggestions Page', () => {
    test('Story: Agency navigates to inbound suggestions from Links Report', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await page.check('text=Orphaned only');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible();

        const dropdown = page.locator('tbody [data-ui-dropdown-trigger]').first();
        if (await dropdown.isVisible({ timeout: 3000 }).catch(() => false)) {
            await dropdown.click();

            const addInbound = page.locator('[data-ui-dropdown-item]:has-text("Add Inbound Links")');
            if (await addInbound.isVisible({ timeout: 2000 }).catch(() => false)) {
                await addInbound.click();
                await page.waitForLoadState('networkidle');
                await dismissOverlays(page);

                const hasTitle = await page.locator('text=Inbound Suggestions').or(page.locator('text=Inbound Link')).first().isVisible({ timeout: 5000 }).catch(() => false);
                expect(hasTitle).toBeTruthy();
            }
        }
    });
});
