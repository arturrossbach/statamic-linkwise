import { test, expect } from '@playwright/test';
import { goToLinkwise, clickTab, dismissOverlays } from './helpers.js';

test.describe('Suggestion Count Consistency', () => {

    test('Outbound suggestion count in table matches modal group count', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        // Wait for suggestion counts to load
        await page.waitForFunction(() => {
            const cells = document.querySelectorAll('td');
            return ![...cells].some(c => c.textContent.trim() === '…');
        }, { timeout: 30000 });

        // Find first entry with outbound suggestions > 0
        const suggButton = page.locator('td:nth-child(7) button').first();
        const tableCount = await suggButton.textContent();
        const count = parseInt(tableCount.trim());

        if (count > 0) {
            // Click to open modal
            await suggButton.click();
            await page.waitForLoadState('networkidle');

            // Wait for modal content
            await page.waitForSelector('[class*="stack"]', { timeout: 10000 });

            // Count groups in modal (rows in tbody)
            const modalDescription = await page.locator('[class*="stack"] p strong').first().textContent();
            const modalCount = parseInt(modalDescription.trim());

            expect(modalCount).toBe(count);
        }
    });

    test('Inbound suggestion count in table matches modal suggestion count', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        // Wait for suggestion counts to load
        await page.waitForFunction(() => {
            const cells = document.querySelectorAll('td');
            return ![...cells].some(c => c.textContent.trim() === '…');
        }, { timeout: 30000 });

        // Find first entry with inbound suggestions > 0
        const suggButton = page.locator('td:nth-child(6) button').first();
        const tableCount = await suggButton.textContent();
        const count = parseInt(tableCount.trim());

        if (count > 0) {
            // Click to open modal
            await suggButton.click();
            await page.waitForLoadState('networkidle');

            // Wait for modal content
            await page.waitForSelector('[class*="stack"]', { timeout: 10000 });

            // Read suggestion count from modal description
            const modalDescription = await page.locator('[class*="stack"] p strong').first().textContent();
            const modalCount = parseInt(modalDescription.trim());

            // Table count should match modal count after modal opened (persist-on-open)
            expect(modalCount).toBe(count);
        }
    });

    test('Suggestion count persists after page reload', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        // Wait for counts to load
        await page.waitForFunction(() => {
            const cells = document.querySelectorAll('td');
            return ![...cells].some(c => c.textContent.trim() === '…');
        }, { timeout: 30000 });

        // Open an outbound suggestion modal to trigger persist-on-open
        const suggButton = page.locator('td:nth-child(7) button').first();
        if (await suggButton.isVisible()) {
            const tableCountBefore = parseInt((await suggButton.textContent()).trim());

            await suggButton.click();
            await page.waitForLoadState('networkidle');
            await page.waitForSelector('[class*="stack"]', { timeout: 10000 });

            // Read modal count
            const modalDescription = await page.locator('[class*="stack"] p strong').first().textContent();
            const modalCount = parseInt(modalDescription.trim());

            // Close modal (click outside or press escape)
            await page.keyboard.press('Escape');
            await page.waitForTimeout(500);

            // Reload page
            await page.reload();
            await page.waitForLoadState('networkidle');
            await clickTab(page, 'Links Report');

            // Wait for counts to load again
            await page.waitForFunction(() => {
                const cells = document.querySelectorAll('td');
                return ![...cells].some(c => c.textContent.trim() === '…');
            }, { timeout: 30000 });

            // Find same button and check count matches what modal showed
            const suggButtonAfter = page.locator('td:nth-child(7) button').first();
            if (await suggButtonAfter.isVisible()) {
                const tableCountAfter = parseInt((await suggButtonAfter.textContent()).trim());
                expect(tableCountAfter).toBe(modalCount);
            }
        }
    });
});
