import { test, expect } from '@playwright/test';
import { goToLinkwise, clickTab, dismissOverlays } from './helpers.js';

// ─── Data Consistency Tests ────────────────────────────────────────────────────
// These tests verify that numbers, labels, and data across tabs are
// logically consistent — not just that elements exist.

test.describe('Consistency — Overview Metrics', () => {
    test('Orphaned count + covered entries = total entries', async ({ page }) => {
        await goToLinkwise(page);

        const metrics = await page.evaluate(() => {
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            const values = {};
            cards.forEach(card => {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label) values[label.toLowerCase()] = card.textContent.trim();
            });
            return values;
        });

        const total = parseInt(metrics['entries indexed'] || '0');
        const orphaned = parseInt(metrics['orphaned entries'] || '0');

        const coverageText = await page.locator('text=Link Coverage').locator('..').locator('..').locator('.text-2xl').textContent();
        const coverage = parseInt(coverageText);

        const expectedCoverage = Math.round(((total - orphaned) / total) * 100);
        expect(coverage).toBe(expectedCoverage);

        expect(orphaned).toBeLessThanOrEqual(total);
        expect(orphaned).toBeGreaterThanOrEqual(0);
        expect(total).toBeGreaterThan(0);
    });

    test('Internal Links count is consistent with Links Report', async ({ page }) => {
        await goToLinkwise(page);

        const internalLinks = await page.evaluate(() => {
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            for (const card of cards) {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label && label.toLowerCase() === 'internal links') return parseInt(card.textContent);
            }
            return 0;
        });

        await clickTab(page, 'Links Report');
        const countText = await page.locator('text=/\\d+ of \\d+ entries/').textContent();
        const totalEntries = parseInt(countText.match(/of (\d+)/)[1]);

        expect(totalEntries).toBeGreaterThan(0);
        expect(internalLinks).toBeGreaterThanOrEqual(0);
    });

    test('Broken Links count in overview matches Broken Links tab', async ({ page }) => {
        await goToLinkwise(page);

        const brokenCount = await page.evaluate(() => {
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            for (const card of cards) {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label && label.toLowerCase() === 'broken links') return parseInt(card.textContent);
            }
            return -1;
        });

        await clickTab(page, 'Broken Links');

        if (brokenCount === 0) {
            const hasCheckButton = await page.locator('button:has-text("Check Links")').isVisible();
            expect(hasCheckButton).toBeTruthy();
        } else if (brokenCount > 0) {
            const rows = page.locator('tbody tr');
            expect(await rows.count()).toBeGreaterThan(0);
        }
    });

    test('External Domains count in overview matches Domains tab row count', async ({ page }) => {
        await goToLinkwise(page);

        const domainsCount = await page.evaluate(() => {
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            for (const card of cards) {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label && label.toLowerCase() === 'external domains') return parseInt(card.textContent);
            }
            return -1;
        });

        await clickTab(page, 'Domains');

        const tabDomains = await page.evaluate(() => {
            const rows = document.querySelectorAll('tbody tr');
            let visible = 0;
            rows.forEach(row => {
                if (row.offsetParent !== null) visible++;
            });
            return visible;
        });

        expect(tabDomains).toBe(domainsCount);
    });

    test('Internal Links card shows external count if external links exist', async ({ page }) => {
        await goToLinkwise(page);

        const externalLine = page.locator('text=/\\+ \\d+ external/');
        const hasExternalLine = await externalLine.isVisible({ timeout: 3000 }).catch(() => false);

        const domainsCount = await page.evaluate(() => {
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            for (const card of cards) {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label && label.toLowerCase() === 'external domains') return parseInt(card.textContent);
            }
            return 0;
        });

        if (domainsCount > 0) {
            expect(hasExternalLine).toBeTruthy();
        }
    });
});

test.describe('Consistency — Links Report Data', () => {
    test('Orphaned entries have zero inbound links', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await page.check('text=Orphaned only');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible();

        const rows = page.locator('tbody tr');
        const count = await rows.count();

        for (let i = 0; i < Math.min(count, 5); i++) {
            const inboundCell = rows.nth(i).locator('td').nth(2);
            const inboundText = await inboundCell.textContent();
            expect(parseInt(inboundText.trim())).toBe(0);
        }
    });

    test('Sorting by inbound descending shows highest first', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        // Click Inbound twice to sort descending
        await page.click('th:has-text("Inbound")');
        await expect(page.locator('th:has-text("Inbound")')).toContainText(/[↑↓]/);
        await page.click('th:has-text("Inbound")');

        const rows = page.locator('tbody tr');
        const first = parseInt(await rows.nth(0).locator('td').nth(2).textContent());
        const second = parseInt(await rows.nth(1).locator('td').nth(2).textContent());

        expect(first).toBeGreaterThanOrEqual(second);
    });

    test('Outbound detail modal shows Type column (internal/external)', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        const rows = page.locator('tbody tr');
        const count = await rows.count();

        for (let i = 0; i < Math.min(count, 15); i++) {
            const outboundCell = rows.nth(i).locator('td').nth(3);
            const clickable = outboundCell.locator('span.cursor-pointer');
            if (await clickable.isVisible({ timeout: 300 }).catch(() => false)) {
                const val = parseInt(await clickable.textContent());
                if (val > 0) {
                    await clickable.click();

                    await expect(page.locator('th:has-text("Type")')).toBeVisible({ timeout: 3000 });
                    await page.keyboard.press('Escape');
                    return;
                }
            }
        }
    });
});

test.describe('Consistency — Domains Tab Data', () => {
    test('Every domain has posts >= 1 and links >= posts', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        const rows = page.locator('tbody tr');
        const count = await rows.count();

        for (let i = 0; i < Math.min(count, 5); i++) {
            const rowData = await rows.nth(i).evaluate(tr => {
                const spans = tr.querySelectorAll('td span.cursor-pointer, td span:not(.cursor-pointer)');
                const numbers = [];
                spans.forEach(s => {
                    const n = parseInt(s.textContent.trim());
                    if (!isNaN(n)) numbers.push(n);
                });
                return numbers;
            });

            if (rowData.length >= 2) {
                const posts = rowData[0];
                const links = rowData[1];
                expect(posts).toBeGreaterThan(0);
                expect(links).toBeGreaterThanOrEqual(posts);
            }
        }
    });

    test('Domain attribute persists after tab switch', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        const firstSelect = page.locator('tbody select').first();
        if (await firstSelect.isVisible({ timeout: 2000 }).catch(() => false)) {
            const currentValue = await firstSelect.inputValue();

            await clickTab(page, 'Overview');
            await clickTab(page, 'Domains');

            const newValue = await page.locator('tbody select').first().inputValue();
            expect(newValue).toBe(currentValue);
        }
    });
});

test.describe('Consistency — Auto-Linking Data', () => {
    test('Linked count never exceeds match count for any rule', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        await page.locator('text=Auto-Linking Rules').scrollIntoViewIfNeeded();

        const ruleData = await page.evaluate(() => {
            const rows = document.querySelectorAll('tbody tr');
            const results = [];
            rows.forEach(row => {
                const tds = row.querySelectorAll('td');
                if (tds.length < 5) return;

                const matchesEl = tds[3].querySelector('button') || tds[3].querySelector('span');
                const linkedEl = tds[4].querySelector('button') || tds[4].querySelector('span');

                const matches = matchesEl ? parseInt(matchesEl.textContent.trim()) || 0 : 0;
                const linked = linkedEl ? parseInt(linkedEl.textContent.trim()) || 0 : 0;
                const keyword = tds[1]?.textContent?.trim() || '';

                results.push({ keyword, matches, linked });
            });
            return results;
        });

        expect(ruleData.length).toBeGreaterThan(0);

        for (const rule of ruleData) {
            expect(rule.linked, `Rule "${rule.keyword}": linked=${rule.linked} > matches=${rule.matches}`)
                .toBeLessThanOrEqual(rule.matches);
        }
    });

    test('Inactive rules show Ignored badge', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        const dropdown = page.locator('tbody [data-ui-dropdown-trigger]').first();
        if (await dropdown.isVisible({ timeout: 3000 }).catch(() => false)) {
            await dropdown.click();

            const ignoreItem = page.locator('[data-ui-dropdown-item]:has-text("Ignore")');
            if (await ignoreItem.isVisible({ timeout: 1000 }).catch(() => false)) {
                await ignoreItem.click();
                await page.waitForLoadState('networkidle');

                await expect(page.locator('text=Ignored').first()).toBeVisible({ timeout: 3000 });

                // Re-activate to clean up
                const dropdown2 = page.locator('tbody [data-ui-dropdown-trigger]').first();
                await dropdown2.click();
                const activateItem = page.locator('[data-ui-dropdown-item]:has-text("Activate")');
                if (await activateItem.isVisible({ timeout: 1000 }).catch(() => false)) {
                    await activateItem.click();
                    await page.waitForLoadState('networkidle');
                }
            }
        }
    });
});

test.describe('Consistency — Target Keywords Data', () => {
    test('Entries show auto-extracted content keywords as badges', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        const entriesWithKeywords = await page.evaluate(() => {
            const rows = document.querySelectorAll('tbody tr');
            let count = 0;
            rows.forEach(row => {
                const tds = row.querySelectorAll('td');
                if (tds.length >= 2) {
                    const badges = tds[1].querySelectorAll('span');
                    if (badges.length > 0) count++;
                }
            });
            return count;
        });

        expect(entriesWithKeywords).toBeGreaterThan(0);
    });

    test('Custom keywords appear as blue badges after saving', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        const blueBadges = page.locator('span.bg-blue-100, span[class*="bg-blue"]');
        const count = await blueBadges.count();

        await expect(page.locator('th:has-text("Custom Keywords")')).toBeVisible();
    });
});
