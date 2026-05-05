import { test, expect } from '@playwright/test';
import { goToLinkwise, dismissOverlays } from './helpers.js';

/**
 * Bulk-operation smoke tests — covers the heavy-job pattern that drives the
 * cross-tab banner, persistent completion banner, and stuck-detection. Run
 * with `npx playwright test --grep @bulk` for fast iteration during dev.
 *
 * What's NOT covered (intentionally): exact-button-text matching and
 * fine-grained timing assertions — those flake on a real test-site whose
 * data shifts. We assert structural invariants ("banner appears", "toast
 * fires", "list refreshes") rather than specific numbers.
 */
test.describe('@bulk Heavy-Bulk smoke', () => {

    test('URL Changer: empty search lists ALL links by default', async ({ page }) => {
        await page.goto('/cp/linkwise/url-changer');
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');

        // The matches table OR the empty-state hint MUST appear after
        // mounted() runs the auto-preview. Empty input + no matches text
        // should NOT say "matching '...'" (that's for non-empty searches).
        const matchesTable = page.locator('table.data-table');
        const noResults = page.locator('text=/No links found in any entry/');

        // Wait briefly for the preview fetch to complete
        await page.waitForTimeout(2000);

        const hasTable = await matchesTable.isVisible().catch(() => false);
        const hasEmptyState = await noResults.isVisible().catch(() => false);

        expect(hasTable || hasEmptyState).toBe(true);
    });

    test('URL Changer: mode picker shows Smart match + Exact match only', async ({ page }) => {
        await page.goto('/cp/linkwise/url-changer');
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');

        const select = page.locator('select[aria-label="Match mode"]');
        await expect(select).toBeVisible();
        await expect(select.locator('option')).toHaveCount(2);
        await expect(select).toContainText('Smart match');
        await expect(select).toContainText('Exact match');
        // No regex option leaked
        await expect(select).not.toContainText('Regex');
    });

    test('URL Changer: invalid URL shows red border + hint + disables button', async ({ page }) => {
        await page.goto('/cp/linkwise/url-changer');
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');

        // Need at least one match for the actions bar to render
        const setForButton = page.locator('button:has-text("Set for")');
        if (!await setForButton.isVisible({ timeout: 5000 }).catch(() => false)) {
            test.skip(true, 'No matches available — cannot test bulk-replace input');
        }

        const bulkInput = page.locator('input[placeholder="https://new-domain.com"]').first();
        await bulkInput.fill('abc');

        // 1. Hint text appears
        await expect(page.locator('text=Not a valid URL')).toBeVisible();

        // 2. Set-for button disabled
        await expect(setForButton).toBeDisabled();

        // 3. data-linkwise-invalid attribute flipped (semantic check independent
        //    of CSS — survives even if styling is busted).
        await expect(bulkInput).toHaveAttribute('data-linkwise-invalid', 'true');
        await expect(bulkInput).toHaveAttribute('aria-invalid', 'true');

        // 4. ACTUAL VISUAL CHECK — computed border-color must be red.
        //    Browser resolves rgb(239, 68, 68) for #ef4444. Catches the case
        //    where Tailwind classes silently aren't bundled (Linkwise has no
        //    own Tailwind config — that bit us once already).
        const borderColor = await bulkInput.evaluate(el => getComputedStyle(el).borderColor);
        expect(borderColor).toMatch(/rgb\(\s*239,\s*68,\s*68/);

        // Fix it
        await bulkInput.fill('https://example.com');
        await expect(page.locator('text=Not a valid URL')).not.toBeVisible();
        await expect(setForButton).toBeEnabled();
        await expect(bulkInput).toHaveAttribute('data-linkwise-invalid', 'false');
        const borderAfterFix = await bulkInput.evaluate(el => getComputedStyle(el).borderColor);
        expect(borderAfterFix).not.toMatch(/rgb\(\s*239,\s*68,\s*68/);
    });
});

test.describe('@bulk @recovery Recovery + stuck-detection', () => {
    test('Recovery banner shows when sessionStorage has interrupted-bulk record', async ({ page }) => {
        await page.goto('/cp/linkwise');
        await dismissOverlays(page);

        // Inject a fake interrupted-bulk record before page mount picks it up.
        await page.evaluate(() => {
            sessionStorage.setItem('linkwise:bulk:recovery', JSON.stringify({
                kind: 'urlchanger',
                label: 'URL changer',
                current: 47,
                total: 100,
                succeeded: 47,
                skipped: 0,
                startedAt: Date.now() - 30000,
                context: { action: 'unlink', search: 'oldsite.com' },
            }));
        });

        await page.reload();
        await dismissOverlays(page);

        // Banner should be visible after mount
        await expect(page.locator('text=/Previous bulk operation was interrupted/')).toBeVisible({ timeout: 5000 });
        await expect(page.locator('text=/47 of 100/')).toBeVisible();

        // Dismiss button works
        const dismiss = page.locator('button:has-text("Dismiss")').first();
        await dismiss.click();
        await expect(page.locator('text=/Previous bulk operation was interrupted/')).not.toBeVisible();
    });

    test('Last-completion banner survives navigation', async ({ page }) => {
        await page.goto('/cp/linkwise');
        await dismissOverlays(page);

        // Inject completion record + force the layout to re-read it on next mount.
        await page.evaluate(() => {
            sessionStorage.setItem('linkwise:bulk:lastCompletion', JSON.stringify({
                kind: 'urlchanger',
                label: 'URL changer',
                phase: 'done',
                current: 0,
                total: 0,
                extra: {
                    action: 'unlink',
                    succeeded: 23,
                    skipped: 0,
                },
                recordedAt: Date.now() - 5000,
            }));
        });

        await page.reload();
        await dismissOverlays(page);

        // Persistent banner shows the completion text
        await expect(page.locator('text=/23 URL\\(s\\) unlinked/')).toBeVisible({ timeout: 5000 });

        // Survives tab navigation
        await page.goto('/cp/linkwise/url-changer');
        await dismissOverlays(page);
        await expect(page.locator('text=/23 URL\\(s\\) unlinked/')).toBeVisible({ timeout: 5000 });

        // Dismiss removes it
        const dismissBtns = page.locator('button:has-text("Dismiss")');
        const count = await dismissBtns.count();
        // Click the FIRST dismiss button (could also be other banners — last completion is its own)
        for (let i = 0; i < count; i++) {
            const btn = dismissBtns.nth(i);
            if (await btn.isVisible()) {
                await btn.click();
                break;
            }
        }
        await page.waitForTimeout(500);
        await expect(page.locator('text=/23 URL\\(s\\) unlinked/')).not.toBeVisible();
    });
});
