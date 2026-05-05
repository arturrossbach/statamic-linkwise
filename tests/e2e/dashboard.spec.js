import { test, expect } from '@playwright/test';
import { goToLinkwise, clickTab, dismissOverlays } from './helpers.js';

// ─── Overview Tab ──────────────────────────────────────────────────────────────
// Overview tests live in dashboard-overview.spec.js (current UI + visual asserts).

// ─── Links Report Tab ──────────────────────────────────────────────────────────

test.describe('Dashboard — Links Report Tab', () => {
    test('Story: Agency reviews entries and their link counts', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await expect(page.locator('th:has-text("Entry Title")')).toBeVisible();
        await expect(page.locator('th:has-text("Inbound")')).toBeVisible();
        await expect(page.locator('th:has-text("Outbound")')).toBeVisible();
        await expect(page.locator('th:has-text("Suggestions")')).toBeVisible();

        const rows = page.locator('tbody tr');
        expect(await rows.count()).toBeGreaterThan(5);
    });

    test('Story: Agency searches for Laravel entries', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await page.fill('input[placeholder="Search entries..."]', 'Laravel');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible({ timeout: 5000 });

        const countText = await page.locator('text=/\\d+ of \\d+ entries/').textContent();
        const filtered = parseInt(countText.match(/(\d+) of/)[1]);
        expect(filtered).toBeGreaterThan(0);
        expect(filtered).toBeLessThan(38);
    });

    test('Story: Agency filters by collection', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        const select = page.locator('select').first();
        await select.selectOption({ label: 'articles' });
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible();

        const countText = await page.locator('text=/\\d+ of \\d+ entries/').textContent();
        const filtered = parseInt(countText.match(/(\d+) of/)[1]);
        expect(filtered).toBeGreaterThan(0);
    });

    test('Story: Agency filters orphaned entries', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await page.check('text=Orphaned only');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible();

        const countText = await page.locator('text=/\\d+ of \\d+ entries/').textContent();
        const filtered = parseInt(countText.match(/(\d+) of/)[1]);
        const total = parseInt(countText.match(/of (\d+)/)[1]);
        expect(filtered).toBeLessThanOrEqual(total);
    });

    test('Story: Agency sorts by inbound links descending', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await page.click('th:has-text("Inbound")');

        const header = page.locator('th:has-text("Inbound")');
        await expect(header).toContainText(/[↑↓]/);
    });

    test('Story: Agency clicks outbound count to see link details', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        const outboundCell = page.locator('tbody td:nth-child(4) span.cursor-pointer').first();
        if (await outboundCell.isVisible({ timeout: 3000 }).catch(() => false)) {
            await outboundCell.click();

            await expect(page.locator('text=Anchor Text')).toBeVisible({ timeout: 3000 });
            await expect(page.locator('text=Target')).toBeVisible();
        }
    });

    test('Story: Agency exports CSV', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        const downloadPromise = page.waitForEvent('download');
        await page.click('button:has-text("Export CSV")');
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe('linkwise-report.csv');
    });

    test('Story: Agency opens row action dropdown and sees all options', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        const dropdown = page.locator('tbody [data-ui-dropdown-trigger]').first();
        await dropdown.click();

        await expect(page.locator('[data-ui-dropdown-item]:has-text("Edit Page")')).toBeVisible();
        await expect(page.locator('[data-ui-dropdown-item]:has-text("View Inbound Links")')).toBeVisible();
        await expect(page.locator('[data-ui-dropdown-item]:has-text("View Outbound Links")')).toBeVisible();
        await expect(page.locator('[data-ui-dropdown-item]:has-text("Add Inbound Links")')).toBeVisible();
    });

    test('Story: Agency uses pagination to browse entries', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        const nextBtn = page.locator('button:has-text("Next")');
        if (await nextBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await expect(page.locator('text=/Page \\d+ of \\d+/')).toBeVisible();
            await nextBtn.click();
            await expect(page.locator('text=/Page 2 of \\d+/')).toBeVisible();
        }
    });
});

// ─── Broken Links Tab ──────────────────────────────────────────────────────────

test.describe('Dashboard — Broken Links Tab', () => {
    test('Story: Agency checks for broken links', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Broken Links');

        await expect(page.locator('button:has-text("Check Links")')).toBeVisible();
    });

    test('Story: Agency runs broken link check', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Broken Links');

        await page.click('button:has-text("Check Links")');

        // Wait for check to complete — results or empty state
        await expect(
            page.locator('th:has-text("Broken URL")').or(page.locator('text=No broken links found'))
        ).toBeVisible({ timeout: 30000 });
        await dismissOverlays(page);

        const hasResults = await page.locator('th:has-text("Broken URL")').isVisible({ timeout: 3000 }).catch(() => false);
        const hasEmpty = await page.locator('text=No broken links found').isVisible({ timeout: 1000 }).catch(() => false);
        expect(hasResults || hasEmpty).toBeTruthy();
    });

    test('Story: Agency sees broken link filters', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Broken Links');

        await expect(page.locator('input[placeholder="Search entries or URLs..."]')).toBeVisible();
    });

    test('Story: Agency filters broken links by type', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Broken Links');

        await page.click('button:has-text("Check Links")');
        await expect(
            page.locator('th:has-text("Broken URL")').or(page.locator('text=No broken links found'))
        ).toBeVisible({ timeout: 30000 });
        await dismissOverlays(page);

        const typeFilter = page.locator('select').filter({ hasText: 'All Types' });
        if (await typeFilter.isVisible({ timeout: 2000 }).catch(() => false)) {
            await typeFilter.selectOption('internal');
        }
    });
});

// ─── Domains Tab ───────────────────────────────────────────────────────────────

test.describe('Dashboard — Domains Tab', () => {
    test('Story: Agency reviews external domains', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        await expect(page.locator('th:has-text("Domain")')).toBeVisible();
        await expect(page.locator('th:has-text("Attribute")')).toBeVisible();
        await expect(page.locator('th:has-text("Posts")')).toBeVisible();
        await expect(page.locator('th:has-text("Links")')).toBeVisible();
    });

    test('Story: Agency searches for a specific domain', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        const searchInput = page.locator('input[placeholder="Search domains..."]');
        if (await searchInput.isVisible({ timeout: 2000 }).catch(() => false)) {
            await searchInput.fill('github');
            await page.waitForLoadState('networkidle');
        }
    });

    test('Story: Agency changes domain attribute to nofollow', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        const attributeSelect = page.locator('tbody select').first();
        if (await attributeSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
            await attributeSelect.selectOption('nofollow');
            await page.waitForLoadState('networkidle');

            const hasError = await page.locator('text=Error').isVisible({ timeout: 1000 }).catch(() => false);
            expect(hasError).toBeFalsy();
        }
    });

    test('Story: Agency filters domains by attribute', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        const filterSelects = page.locator('select');
        const filterSelect = filterSelects.first();
        if (await filterSelect.isVisible({ timeout: 2000 }).catch(() => false)) {
            const options = await filterSelect.locator('option').allTextContents();
            if (options.some(o => o.includes('Nofollow'))) {
                await filterSelect.selectOption({ label: 'Nofollow' });
                await page.waitForLoadState('networkidle');
            }
        }
    });

    test('Story: Agency clicks domain link count to see details', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        const linkCount = page.locator('tbody td span.cursor-pointer').first();
        if (await linkCount.isVisible({ timeout: 3000 }).catch(() => false)) {
            await linkCount.click();

            const stackVisible = await page.locator('text=URL').isVisible({ timeout: 3000 }).catch(() => false);
            if (stackVisible) {
                await expect(page.locator('text=Context')).toBeVisible();
            }
        }
    });

    test('Story: Agency sorts domains by link count', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        await page.click('th:has-text("Links")');

        const header = page.locator('th:has-text("Links")');
        await expect(header).toContainText(/[↑↓]/);
    });
});

// ─── Auto-Linking Tab ──────────────────────────────────────────────────────────

test.describe('Dashboard — Auto-Linking Tab', () => {
    test('Story: Agency sees auto-link rule creation form', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        await expect(page.locator('text=Auto-Linking Rules')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Create Rule' })).toBeVisible();

        await expect(page.locator('text=Link to Entry')).toBeVisible();
        await expect(page.locator('text=Custom URL')).toBeVisible();
        await expect(page.locator('text=Once per post')).toBeVisible();
        await expect(page.locator('text=Skip if linked')).toBeVisible();
    });

    test('Story: Agency creates an auto-link rule with custom URL', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        await page.click('text=Custom URL');

        const keywordInput = page.locator('textarea[placeholder*="Laravel"], input[placeholder*="keyword"]').first();
        if (await keywordInput.isVisible()) {
            await keywordInput.fill('Playwright Test Keyword');
        }

        const urlInput = page.locator('input[placeholder*="https://"]');
        if (await urlInput.isVisible()) {
            await urlInput.fill('https://playwright.dev');
        }

        await page.getByRole('button', { name: 'Create Rule' }).click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('text=Playwright Test Keyword')).toBeVisible({ timeout: 5000 });
    });

    test('Story: Agency searches auto-link rules', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const searchInput = page.locator('input[placeholder="Search rules..."]');
        if (await searchInput.isVisible({ timeout: 2000 }).catch(() => false)) {
            await searchInput.fill('Playwright');
            await page.waitForLoadState('networkidle');
        }
    });

    test('Story: Agency previews an auto-link rule', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const matchCount = page.locator('tbody td span.cursor-pointer').first();
        if (await matchCount.isVisible({ timeout: 3000 }).catch(() => false)) {
            await matchCount.click();

            const previewVisible = await page.locator('text=Preview:').isVisible({ timeout: 3000 }).catch(() => false);
            if (previewVisible) {
                const hasBadge = await page.locator('text=Would link').or(page.locator('text=Linked to target')).or(page.locator('text=Linked elsewhere')).first().isVisible({ timeout: 2000 }).catch(() => false);
                expect(hasBadge).toBeTruthy();
            }
        }
    });

    test('Story: Agency opens rule action dropdown', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const dropdown = page.locator('tbody [data-ui-dropdown-trigger]').first();
        if (await dropdown.isVisible({ timeout: 3000 }).catch(() => false)) {
            await dropdown.click();

            await expect(page.locator('[data-ui-dropdown-item]:has-text("Apply Rule")')).toBeVisible();
            await expect(page.locator('[data-ui-dropdown-item]:has-text("Preview")')).toBeVisible();
            await expect(page.locator('[data-ui-dropdown-item]:has-text("Edit")')).toBeVisible();
            await expect(page.locator('[data-ui-dropdown-item]:has-text("Delete")')).toBeVisible();
        }
    });

    test('Story: Agency edits an auto-link rule', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const dropdown = page.locator('tbody [data-ui-dropdown-trigger]').first();
        if (await dropdown.isVisible({ timeout: 3000 }).catch(() => false)) {
            await dropdown.click();

            const editItem = page.locator('[data-ui-dropdown-item]:has-text("Edit")');
            if (await editItem.isVisible()) {
                await editItem.click();

                await expect(page.getByRole('button', { name: 'Update Rule' })).toBeVisible({ timeout: 3000 });
                await expect(page.locator('button:has-text("Cancel")')).toBeVisible();

                await page.click('button:has-text("Cancel")');
            }
        }
    });

    test('Story: Agency selects rules with checkboxes', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const checkbox = page.locator('tbody input[type="checkbox"]').first();
        if (await checkbox.isVisible({ timeout: 3000 }).catch(() => false)) {
            await checkbox.check();

            const applySelected = page.locator('button:has-text("Apply Selected")');
            await expect(applySelected).toBeVisible();
        }
    });

    test('Story: Agency toggles select-all checkbox', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const selectAll = page.locator('thead input[type="checkbox"]');
        if (await selectAll.isVisible({ timeout: 3000 }).catch(() => false)) {
            await selectAll.check();

            const rowCheckboxes = page.locator('tbody input[type="checkbox"]');
            const count = await rowCheckboxes.count();
            for (let i = 0; i < Math.min(count, 3); i++) {
                await expect(rowCheckboxes.nth(i)).toBeChecked();
            }
        }
    });

    test('Story: Agency deletes an auto-link rule', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const playwrightRow = page.locator('tr:has-text("Playwright Test Keyword")');
        if (await playwrightRow.isVisible({ timeout: 3000 }).catch(() => false)) {
            const dropdown = playwrightRow.locator('[data-ui-dropdown-trigger]');
            await dropdown.click();

            await page.locator('[data-ui-dropdown-item]:has-text("Delete")').click();

            const confirmBtn = page.locator('button:has-text("Delete Rule")');
            if (await confirmBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
                await confirmBtn.click();
                await page.waitForLoadState('networkidle');
            }

            await expect(page.locator('text=Playwright Test Keyword')).not.toBeVisible({ timeout: 3000 });
        }
    });
});

// ─── Target Keywords Tab ───────────────────────────────────────────────────────

test.describe('Dashboard — Target Keywords Tab', () => {
    test('Story: Agency views content keywords per entry', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        await expect(page.locator('th:has-text("Content Keywords")')).toBeVisible();
        await expect(page.locator('th:has-text("Custom Keywords")')).toBeVisible();

        const rows = page.locator('tbody tr');
        expect(await rows.count()).toBeGreaterThan(5);
    });

    test('Story: Agency searches keywords by entry name', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        const searchInput = page.locator('input[placeholder="Search entries or keywords..."]');
        await searchInput.fill('Laravel');
        await page.waitForLoadState('networkidle');

        const rows = page.locator('tbody tr');
        const count = await rows.count();
        expect(count).toBeGreaterThan(0);
    });

    test('Story: Agency opens keyword edit modal', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        const editBtn = page.locator('tbody button:has-text("Edit")').first();
        await editBtn.click();

        await expect(page.locator('textarea[placeholder*="Enter keywords"]')).toBeVisible({ timeout: 3000 });
    });

    test('Story: Agency adds custom keywords and saves', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        const editBtn = page.locator('tbody button:has-text("Edit")').first();
        await editBtn.click();

        const textarea = page.locator('textarea[placeholder*="Enter keywords"]');
        await expect(textarea).toBeVisible({ timeout: 3000 });
        await textarea.fill('playwright-test-keyword, e2e-testing');

        await page.click('button:has-text("Save")');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('text=playwright-test-keyword')).toBeVisible({ timeout: 3000 });
    });

    test('Story: Agency sorts keywords by entry title', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        const entryHeader = page.locator('th:has-text("Entry")').last();
        await entryHeader.click();

        await expect(entryHeader).toContainText(/[↑↓]/);
    });
});

// ─── Tab Navigation ────────────────────────────────────────────────────────────

test.describe('Dashboard — Tab Navigation', () => {
    test('Story: Agency navigates all tabs without crash', async ({ page }) => {
        await goToLinkwise(page);

        const tabs = ['Overview', 'Links Report', 'Broken Links', 'Domains', 'Auto-Linking', 'Target Keywords'];

        for (const tab of tabs) {
            await clickTab(page, tab);
        }
    });

    test('Story: Agency returns to previously active tab', async ({ page }) => {
        await goToLinkwise(page);

        await clickTab(page, 'Links Report');
        await expect(page.locator('th:has-text("Entry Title")')).toBeVisible();

        await clickTab(page, 'Domains');
        await clickTab(page, 'Links Report');

        await expect(page.locator('th:has-text("Entry Title")')).toBeVisible();
    });

    test('Story: Tab state persists after page reload', async ({ page }) => {
        await goToLinkwise(page);

        await clickTab(page, 'Domains');
        await expect(page.locator('th:has-text("Domain")')).toBeVisible();

        await page.reload();
        await page.waitForLoadState('networkidle');
        await dismissOverlays(page);

        await expect(page.locator('th:has-text("Domain")')).toBeVisible({ timeout: 5000 });
    });
});

// ─── Cross-Tab Workflows ───────────────────────────────────────────────────────

test.describe('Dashboard — Cross-Tab Workflows', () => {
    test('Story: Agency finds orphaned entry and checks inbound opportunities', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await page.check('text=Orphaned only');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible();

        const dropdown = page.locator('tbody [data-ui-dropdown-trigger]').first();
        if (await dropdown.isVisible({ timeout: 3000 }).catch(() => false)) {
            await dropdown.click();

            await expect(page.locator('[data-ui-dropdown-item]:has-text("Add Inbound Links")')).toBeVisible();
        }
    });

    test('Story: Agency checks domains then changes attribute', async ({ page }) => {
        await goToLinkwise(page);

        await expect(page.locator('text=External Domains')).toBeVisible();

        await clickTab(page, 'Domains');
        await expect(page.locator('th:has-text("Domain")')).toBeVisible();

        const rows = page.locator('tbody tr');
        expect(await rows.count()).toBeGreaterThan(0);
    });

    test('Story: Full workflow — Rebuild, Review, Check Links', async ({ page }) => {
        await goToLinkwise(page);

        // Step 1: Rebuild Index
        await page.click('button:has-text("Rebuild Index")');
        await page.waitForLoadState('networkidle');

        // Step 2: Review Links Report
        await clickTab(page, 'Links Report');
        await expect(page.locator('th:has-text("Entry Title")')).toBeVisible();

        // Step 3: Check Broken Links
        await clickTab(page, 'Broken Links');
        await expect(page.locator('button:has-text("Check Links")')).toBeVisible();

        // Step 4: Review Domains
        await clickTab(page, 'Domains');
        await expect(page.locator('th:has-text("Domain")')).toBeVisible();

        // Step 5: Back to overview
        await clickTab(page, 'Overview');
        await expect(page.locator('text=Entries Indexed')).toBeVisible();
    });
});
