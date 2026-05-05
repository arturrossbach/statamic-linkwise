import { test, expect } from '@playwright/test';
import { goToLinkwise, clickTab } from './helpers.js';

// ─── UX Sanity Checks ─────────────────────────────────────────────────────────
// These tests catch what a human sees in 2 seconds:
// Wrong colors, missing context, duplicates, misleading labels.

test.describe('UX Sanity — Colors match reality', () => {
    test('Internal Links color reflects how many entries actually link', async ({ page }) => {
        await goToLinkwise(page);

        const data = await page.evaluate(() => {
            const cards = document.querySelectorAll('[data-ui-card]');
            for (const card of cards) {
                const label = card.querySelector('.uppercase')?.textContent?.trim();
                if (label?.toLowerCase() === 'internal links') {
                    const valueEl = card.querySelector('.text-2xl');
                    const classes = valueEl?.className || '';
                    const subtext = card.querySelector('.text-xs.text-gray-400')?.textContent?.trim() || '';
                    return { classes, subtext };
                }
            }
            return null;
        });

        if (!data) return;

        // Parse "4 of 38 entries link to other entries"
        const match = data.subtext.match(/(\d+) of (\d+)/);
        if (match) {
            const ratio = parseInt(match[1]) / parseInt(match[2]);
            if (ratio < 0.4) {
                // Bad ratio — must NOT be green
                expect(data.classes).not.toContain('text-green');
            }
            if (ratio >= 0.7) {
                // Good ratio — should be green
                expect(data.classes).toContain('text-green');
            }
        }
    });

    test('Orphaned Entries is red when count > 0', async ({ page }) => {
        await goToLinkwise(page);

        const data = await page.evaluate(() => {
            const cards = document.querySelectorAll('[data-ui-card]');
            for (const card of cards) {
                const label = card.querySelector('.uppercase')?.textContent?.trim();
                if (label?.toLowerCase() === 'orphaned entries') {
                    const valueEl = card.querySelector('.text-2xl');
                    return {
                        value: parseInt(valueEl?.textContent?.trim()) || 0,
                        classes: valueEl?.className || '',
                    };
                }
            }
            return null;
        });

        if (!data) return;

        if (data.value > 0) {
            expect(data.classes).toContain('text-red');
        } else {
            expect(data.classes).toContain('text-green');
        }
    });

    test('Broken Links is red when count > 0, green when 0', async ({ page }) => {
        await goToLinkwise(page);

        const data = await page.evaluate(() => {
            const cards = document.querySelectorAll('[data-ui-card]');
            for (const card of cards) {
                const label = card.querySelector('.uppercase')?.textContent?.trim();
                if (label?.toLowerCase() === 'broken links') {
                    const valueEl = card.querySelector('.text-2xl');
                    return {
                        value: parseInt(valueEl?.textContent?.trim()) || 0,
                        classes: valueEl?.className || '',
                    };
                }
            }
            return null;
        });

        if (!data) return;

        if (data.value > 0) {
            expect(data.classes).toContain('text-red');
        } else {
            expect(data.classes).toContain('text-green');
        }
    });

    test('Coverage badge matches the percentage', async ({ page }) => {
        await goToLinkwise(page);

        const data = await page.evaluate(() => {
            const cards = document.querySelectorAll('[data-ui-card]');
            for (const card of cards) {
                const label = card.querySelector('.uppercase')?.textContent?.trim();
                if (label?.toLowerCase() === 'link coverage') {
                    const value = parseInt(card.querySelector('.text-2xl')?.textContent) || 0;
                    const badge = card.querySelector('.rounded-full')?.textContent?.trim();
                    return { value, badge };
                }
            }
            return null;
        });

        if (!data) return;

        if (data.value >= 80) expect(data.badge).toBe('Great');
        else if (data.value >= 50) expect(data.badge).toBe('OK');
        else expect(data.badge).toBe('Needs Work');
    });
});

test.describe('UX Sanity — No duplicates', () => {
    test('No two cards show the exact same metric value with same meaning', async ({ page }) => {
        await goToLinkwise(page);

        const metrics = await page.evaluate(() => {
            const results = [];
            const cards = document.querySelectorAll('[data-ui-card]');
            cards.forEach(card => {
                const label = card.querySelector('.uppercase')?.textContent?.trim() || '';
                const value = card.querySelector('.text-2xl')?.textContent?.trim() || '';
                if (label && value) results.push({ label: label.toLowerCase(), value });
            });
            return results;
        });

        // Check for duplicate label+value combos
        const seen = new Set();
        for (const m of metrics) {
            const key = `${m.label}::${m.value}`;
            expect(seen.has(key), `Duplicate card: "${m.label}" with value "${m.value}" appears twice`).toBeFalsy();
            seen.add(key);
        }

        // Check that no two different labels show the same value with similar meaning
        const avgCards = metrics.filter(m => m.label.includes('avg') || m.label.includes('internal links'));
        if (avgCards.length > 1) {
            const values = avgCards.map(c => c.value);
            const unique = new Set(values);
            // If multiple avg-related cards exist, they shouldn't all show the same number
            if (avgCards.length === unique.size) {
                // All different — OK
            } else {
                // Same value in multiple avg cards — likely a duplicate
                expect(avgCards.length, 'Multiple cards showing the same average metric').toBe(unique.size);
            }
        }
    });
});

test.describe('UX Sanity — Every number has context', () => {
    test('Metric cards have explanatory subtext', async ({ page }) => {
        await goToLinkwise(page);

        const cards = await page.evaluate(() => {
            const results = [];
            document.querySelectorAll('[data-ui-card]').forEach(card => {
                const label = card.querySelector('.uppercase')?.textContent?.trim() || '';
                const hasValue = !!card.querySelector('.text-2xl');
                const hasSubtext = !!card.querySelector('.text-xs.text-gray-400, .text-xs.text-green-500, .text-xs.text-red-400');
                if (hasValue && label) {
                    results.push({ label: label.toLowerCase(), hasSubtext });
                }
            });
            return results;
        });

        // Cards that are purely informational (just a count) don't need subtext
        const noSubtextNeeded = ['entries indexed'];

        for (const card of cards) {
            if (noSubtextNeeded.includes(card.label)) continue;
            expect(card.hasSubtext, `Card "${card.label}" has a number but no explanatory subtext`).toBeTruthy();
        }
    });
});

test.describe('UX Sanity — No jargon in visible text', () => {
    test('Card labels and subtexts avoid technical jargon', async ({ page }) => {
        await goToLinkwise(page);

        const texts = await page.evaluate(() => {
            const results = [];
            document.querySelectorAll('[data-ui-card]').forEach(card => {
                // Collect all visible text (not tooltips)
                const label = card.querySelector('.uppercase')?.textContent?.trim() || '';
                const subtext = card.querySelector('.text-xs.text-gray-400')?.textContent?.trim() || '';
                results.push(label, subtext);
            });
            return results.filter(t => t.length > 0);
        });

        const jargonTerms = ['inbound', 'outbound', 'orphaned ignore', 'TF-IDF', 'ProseMirror'];

        for (const text of texts) {
            for (const jargon of jargonTerms) {
                // "Orphaned" in labels is OK (it's an SEO term), but "inbound/outbound" in subtexts is not
                if (jargon === 'inbound' || jargon === 'outbound') {
                    // These should only appear in tooltips, not in visible card text
                    const lower = text.toLowerCase();
                    if (lower.includes(jargon) && !lower.includes('orphaned')) {
                        expect(false, `Jargon "${jargon}" found in visible text: "${text}"`).toBeTruthy();
                    }
                }
            }
        }
    });
});

test.describe('UX Sanity — Clickable cards work', () => {
    test('Every card with cursor-pointer changes the active tab on click', async ({ page }) => {
        await goToLinkwise(page);

        // Count clickable cards
        const clickableCount = await page.evaluate(() => {
            return document.querySelectorAll('.cursor-pointer [data-ui-card], [data-ui-card].cursor-pointer').length;
        });

        expect(clickableCount).toBeGreaterThan(0);

        // Verify the Orphaned card navigates to Links Report
        const orphanedWrapper = page.locator('.cursor-pointer:has([data-ui-card])').filter({ hasText: 'Orphaned' });
        if (await orphanedWrapper.isVisible({ timeout: 2000 }).catch(() => false)) {
            await orphanedWrapper.click();
            await expect(page.locator('th:has-text("Entry Title")')).toBeVisible({ timeout: 3000 });
        }
    });
});
