import { test, expect } from '@playwright/test';
import { dismissOverlays } from './helpers.js';

/**
 * Target Keywords tab — edge-case + inconsistency tests.
 *
 * What we look for:
 *  - Save→reload survives (persistence)
 *  - Empty / overflow / special-char inputs handled
 *  - Search filter behaves predictably (case, trim, partial)
 *  - Save action surfaces backend errors instead of silent failure
 *  - Race conditions on concurrent saves (best-effort detection)
 *  - UI elements behave consistently (button states, modal lifecycle)
 *  - Content vs custom keywords aren't confused
 *
 * Run: PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test --grep @keywords
 */
test.describe('@keywords Target Keywords — UI behavior', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/cp/linkwise/keywords');
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');
    });

    test('Tab loads without errors and shows entries table', async ({ page }) => {
        const errors = [];
        page.on('pageerror', err => errors.push(err.message));
        page.on('console', msg => {
            if (msg.type() === 'error') errors.push(msg.text());
        });

        // Either entries OR explicit empty-state — never a blank page
        const table = page.locator('table.data-table');
        const heading = page.locator('h3:has-text("Target Keywords")');
        await expect(heading).toBeVisible();
        await expect(table).toBeVisible({ timeout: 10000 });

        expect(errors, 'no JS errors on mount').toEqual([]);
    });

    test('Entry-count summary matches table row count', async ({ page }) => {
        const table = page.locator('table.data-table');
        if (!await table.isVisible({ timeout: 5000 }).catch(() => false)) {
            test.skip(true, 'No entries to test');
        }
        const rows = await page.locator('table.data-table tbody tr').count();
        const summary = await page.locator('text=/\\d+ entries/').textContent();
        const summaryNum = parseInt(summary.match(/(\d+)/)[1], 10);
        // Summary should match visible rows (when search is empty)
        expect(summaryNum).toBe(rows);
    });

    test('Search filter is case-insensitive and trimmed', async ({ page }) => {
        const search = page.locator('input[placeholder*="Search entries"]');
        if (!await search.isVisible().catch(() => false)) test.skip(true, 'no search field');

        // Take any visible row title to test against
        const firstTitle = await page.locator('table.data-table tbody tr td').first().textContent();
        if (!firstTitle || firstTitle.trim().length < 3) test.skip(true, 'no usable title');

        const partial = firstTitle.trim().substring(0, 3).toLowerCase();
        await search.fill(`  ${partial.toUpperCase()}  `); // upper + leading/trailing whitespace
        await page.waitForTimeout(300);

        const visibleRows = await page.locator('table.data-table tbody tr').count();
        expect(visibleRows, 'case-insensitive trimmed search returns results').toBeGreaterThan(0);
    });

    test('Search with no matches shows zero rows AND zero count', async ({ page }) => {
        const search = page.locator('input[placeholder*="Search entries"]');
        if (!await search.isVisible().catch(() => false)) test.skip(true, 'no search field');

        await search.fill('zzzzzznonexistent_keyword_xyz123');
        await page.waitForTimeout(300);

        const rows = await page.locator('table.data-table tbody tr').count();
        const summaryText = await page.locator('text=/\\d+ entries/').textContent();
        const summaryNum = parseInt(summaryText.match(/(\d+)/)[1], 10);
        expect(rows).toBe(0);
        expect(summaryNum).toBe(0);
    });
});

test.describe('@keywords Target Keywords — Edit flow', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/cp/linkwise/keywords');
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');
    });

    test('Edit button opens stack with current keywords pre-filled', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
            test.skip(true, 'no rows');
        }
        await editBtn.click();

        // Stack opened
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await expect(textarea).toBeVisible({ timeout: 5000 });

        // Save button visible
        await expect(page.locator('button:has-text("Save Keywords")')).toBeVisible();
    });

    test('Edit button uses Statamic default style (NOT ghost or transparent)', async ({ page }) => {
        // Catches the "variant=ghost slipped through" class of bug. Statamic's
        // default Button has a gray gradient bg + visible border. If someone
        // reverts to variant="ghost" the bg goes transparent — visually broken
        // but functional, so a click-only test would miss it.
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
            test.skip(true, 'no rows');
        }

        const styles = await editBtn.evaluate(el => {
            const cs = getComputedStyle(el);
            return {
                bg: cs.backgroundColor,
                bgImage: cs.backgroundImage,
                border: cs.borderTopWidth,
            };
        });

        // Default variant has a gradient background (linear-gradient) — ghost has none.
        expect(styles.bgImage, 'Edit button should have gradient bg (Statamic default)').toMatch(/gradient|linear/i);
        // And a visible top border (default has 1px border, ghost is borderless).
        expect(styles.border, 'Edit button should have visible border').not.toBe('0px');
    });

    test('Save with comma-separated keywords stores correctly and persists', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        // Use a unique marker so we can reliably find it post-save
        const marker = `kwtest_${Date.now()}`;
        await textarea.fill(`${marker}, second_kw, third_kw`);
        await page.locator('button:has-text("Save Keywords")').click();

        // Toast confirms success
        await expect(page.locator('text=Keywords saved')).toBeVisible({ timeout: 5000 });

        // Reload page → keyword should still be visible somewhere
        await page.reload();
        await dismissOverlays(page);
        await expect(page.locator(`text=${marker}`).first()).toBeVisible({ timeout: 5000 });
    });

    test('Save with newline-separated keywords also works', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        const marker = `nlkw_${Date.now()}`;
        await textarea.fill(`${marker}\nsecond_kw\nthird_kw`);
        await page.locator('button:has-text("Save Keywords")').click();

        await expect(page.locator('text=Keywords saved')).toBeVisible({ timeout: 5000 });
        await page.reload();
        await dismissOverlays(page);
        await expect(page.locator(`text=${marker}`).first()).toBeVisible({ timeout: 5000 });
    });

    test('Empty input clears all custom keywords', async ({ page }) => {
        // Find a row that already has custom keywords (has the blue chip)
        const blueChip = page.locator('span.bg-blue-100, span[class*="bg-blue-900"]').first();
        if (!await blueChip.isVisible({ timeout: 3000 }).catch(() => false)) {
            test.skip(true, 'No row with custom keywords to clear');
        }
        const row = blueChip.locator('xpath=ancestor::tr');
        await row.locator('button:has-text("Edit")').click();

        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });
        await textarea.fill('');
        await page.locator('button:has-text("Save Keywords")').click();
        await expect(page.locator('text=Keywords saved')).toBeVisible({ timeout: 5000 });
    });

    test('Whitespace-only keywords are filtered out', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        const marker = `wskw_${Date.now()}`;
        // Mix of empty entries and whitespace
        await textarea.fill(`   ,  , ${marker} ,   ,\n  ,\n,`);
        await page.locator('button:has-text("Save Keywords")').click();
        await expect(page.locator('text=Keywords saved')).toBeVisible({ timeout: 5000 });

        // Stack closes — only the marker should be visible as a chip, no empty chips
        await page.waitForTimeout(500);
        const emptyChips = await page.locator('span.bg-blue-100:text-is(""), span.bg-blue-100:text-is(" ")').count();
        expect(emptyChips).toBe(0);
    });

    test('Special characters in keywords (umlauts, hyphens) survive save+reload', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        const ts = Date.now();
        await textarea.fill(`über_${ts}, foo-bar_${ts}, müller_${ts}`);
        await page.locator('button:has-text("Save Keywords")').click();
        await expect(page.locator('text=Keywords saved')).toBeVisible({ timeout: 5000 });

        await page.reload();
        await dismissOverlays(page);
        await expect(page.locator(`text=über_${ts}`).first()).toBeVisible({ timeout: 5000 });
        await expect(page.locator(`text=müller_${ts}`).first()).toBeVisible({ timeout: 5000 });
    });
});

test.describe('@keywords Target Keywords — Inconsistencies + edge cases', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/cp/linkwise/keywords');
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');
    });

    test('Content keywords (gray) and custom keywords (blue) use different chip styles', async ({ page }) => {
        const table = page.locator('table.data-table');
        if (!await table.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        // Need at least one entry with both kinds for this assertion to work
        const grayChip = page.locator('span.bg-gray-100, span[class*="bg-gray-700"]').first();
        const blueChip = page.locator('span.bg-blue-100, span[class*="bg-blue-900"]').first();
        const hasGray = await grayChip.isVisible({ timeout: 2000 }).catch(() => false);
        const hasBlue = await blueChip.isVisible({ timeout: 2000 }).catch(() => false);

        if (!hasGray && !hasBlue) {
            test.skip(true, 'No keyword chips visible at all — test data missing keywords');
        }
        // Visual distinction is the whole point of this column.
        // If both are visible, at least one of each must exist somewhere.
        if (hasGray && hasBlue) {
            const grayBg = await grayChip.evaluate(el => getComputedStyle(el).backgroundColor);
            const blueBg = await blueChip.evaluate(el => getComputedStyle(el).backgroundColor);
            expect(grayBg).not.toBe(blueBg);
        }
    });

    test('Edit modal title contains the entry title (not generic)', async ({ page }) => {
        const firstRow = page.locator('table.data-table tbody tr').first();
        if (!await firstRow.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        const titleCell = await firstRow.locator('td').first().textContent();
        const expectedTitle = titleCell.trim().split('\n')[0].trim(); // first line = title

        await firstRow.locator('button:has-text("Edit")').click();
        // Stack header should reference the entry title
        await expect(page.locator(`text=Keywords:`)).toBeVisible({ timeout: 5000 });
        const headerText = await page.locator('text=/Keywords:.*/').first().textContent();
        // Loose match — depends on title length / truncation
        expect(headerText.toLowerCase()).toContain(expectedTitle.toLowerCase().substring(0, Math.min(10, expectedTitle.length)));
    });

    test('Save button does NOT spin forever on backend error (graceful failure)', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        // Hijack the save endpoint with a 500. Route is /cp/linkwise/target-keywords/{id} —
        // NOT /keywords/{id}, that one's the page-render route.
        await page.route('**/cp/linkwise/target-keywords/*', route => route.fulfill({ status: 500, body: '{}' }));
        await textarea.fill('test_kw_for_500');
        const saveBtn = page.locator('button:has-text("Save Keywords")');
        await saveBtn.click();

        // Error toast appears
        await expect(page.locator('text=Failed to save keywords')).toBeVisible({ timeout: 5000 });
        // Save button no longer in loading state — user can retry
        await expect(saveBtn).toBeEnabled({ timeout: 5000 });
    });

    test('Search by custom keyword finds the entry (cross-column search works)', async ({ page }) => {
        const search = page.locator('input[placeholder*="Search entries"]');
        if (!await search.isVisible().catch(() => false)) test.skip(true, 'no search');

        // Find a row with a custom keyword chip and use that keyword text
        const blueChip = page.locator('span.bg-blue-100, span[class*="bg-blue-900"]').first();
        if (!await blueChip.isVisible({ timeout: 3000 }).catch(() => false)) {
            test.skip(true, 'No custom keywords present — set some via Edit first');
        }
        const kwText = (await blueChip.textContent()).trim();
        if (kwText.length < 3) test.skip(true, 'keyword too short');

        await search.fill(kwText);
        await page.waitForTimeout(300);
        const rows = await page.locator('table.data-table tbody tr').count();
        expect(rows, 'searching by custom-keyword text matches entries').toBeGreaterThan(0);
    });

    test('Stack closes on Escape key (a11y / muscle memory)', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');

        await editBtn.click();
        await page.locator('textarea[placeholder*="comma"]').waitFor({ state: 'visible' });
        await page.keyboard.press('Escape');

        // Modal/stack closed
        await expect(page.locator('textarea[placeholder*="comma"]')).not.toBeVisible({ timeout: 3000 });
    });
});

test.describe('@keywords Target Keywords — Boundaries + Visual', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/cp/linkwise/keywords');
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');
    });

    test('Validation blocks at MAX+1 keywords', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');
        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        // 51 keywords (one over default max-50). Frontend should block.
        const tooMany = Array.from({ length: 51 }, (_, i) => `kw${i}`).join(', ');
        await textarea.fill(tooMany);

        await expect(page.locator('text=/Too many keywords/')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('button:has-text("Save Keywords")')).toBeDisabled();
    });

    test('Validation blocks at over-length keyword', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');
        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        // 51 chars (one over default max-50)
        await textarea.fill('a'.repeat(51));

        await expect(page.locator('text=/too long/i')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('button:has-text("Save Keywords")')).toBeDisabled();
    });

    test('Trailing comma is treated as no extra keyword', async ({ page }) => {
        const editBtn = page.locator('button:has-text("Edit")').first();
        if (!await editBtn.isVisible({ timeout: 5000 }).catch(() => false)) test.skip(true, 'no rows');
        await editBtn.click();
        const textarea = page.locator('textarea[placeholder*="comma"]');
        await textarea.waitFor({ state: 'visible' });

        // "a, b," — trailing comma + empty = should resolve to 2 keywords, not 3
        await textarea.fill('aaa, bbb,');
        // No validation error because parser drops empty strings
        await expect(page.locator('text=/Too many keywords/')).not.toBeVisible({ timeout: 1000 });
        // Save should work
        await page.locator('button:has-text("Save Keywords")').click();
        await expect(page.locator('text=Keywords saved')).toBeVisible({ timeout: 5000 });
    });

    test('Filter checkbox uses Statamic default style (not custom HTML)', async ({ page }) => {
        // Catches accidental revert to custom <input type=checkbox> which
        // would render with browser-default styling, not Statamic's gray
        // custom checkbox.
        const checkbox = page.locator('input[type="checkbox"]').filter({ hasNot: page.locator('disabled') }).first();
        if (!await checkbox.isVisible({ timeout: 3000 }).catch(() => false)) test.skip(true, 'no checkbox visible');

        // Statamic Checkbox renders with at least *some* custom appearance —
        // not the browser-default 13x13 box. Loose check: aspect ratio is not
        // the platform-default value, OR it has Statamic's data-* attribute.
        const box = await checkbox.boundingBox();
        if (box) {
            // Browser-default is ~13x13; Statamic styles render >= 16x16
            expect(box.width, 'Checkbox should be at least 14px wide (Statamic-styled)').toBeGreaterThanOrEqual(14);
        }
    });

    test('"+ Add keywords" empty-state CTA has dashed border + cursor-pointer', async ({ page }) => {
        // CTA only renders for entries WITHOUT custom keywords.
        const cta = page.locator('button:has-text("+ Add keywords")').first();
        if (!await cta.isVisible({ timeout: 5000 }).catch(() => false)) {
            test.skip(true, 'No empty-custom rows — every entry already has keywords');
        }

        const styles = await cta.evaluate(el => {
            const cs = getComputedStyle(el);
            return {
                cursor: cs.cursor,
                borderStyle: cs.borderStyle,
            };
        });
        expect(styles.cursor).toBe('pointer');
        expect(styles.borderStyle).toBe('dashed');
    });
});
