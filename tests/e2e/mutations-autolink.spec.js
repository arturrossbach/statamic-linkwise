import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Auto-Linking — Mutating Actions ───────────────────────────────────────────
// Save Rule (Create/Update), Bulk Activate/Delete, Apply Rule, Import CSV.

test.describe('@mutations Auto-Linking — Action wiring', () => {
    test('Create Rule fires store endpoint with correct payload', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/autolink');
        await page.waitForLoadState('networkidle');

        let captured = null;
        await page.route('**/cp/linkwise/autolink/rules', async (route) => {
            // Only intercept POST (store), not GET (index)
            if (route.request().method() !== 'POST') return route.continue();
            captured = await route.request().postDataJSON();
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    success: true,
                    rule: {
                        id: 'test-id',
                        keyword: captured.keyword,
                        url: captured.url,
                        active: true,
                        case_sensitive: false,
                        once_per_post: true,
                        skip_if_exists: false,
                        auto_apply_on_save: 'follow_global',
                        collections: [],
                        match_count: 0,
                        linked_count: 0,
                        last_applied_at: null,
                        last_applied_links_added: 0,
                    },
                }),
            });
        });

        // Switch to URL mode (avoid entry-picker complexity)
        const urlRadio = page.locator('input[type="radio"]').nth(1);
        if (await urlRadio.isVisible({ timeout: 1000 }).catch(() => false)) {
            await urlRadio.check({ force: true });
        }

        // Fill keyword + URL
        const keywordInput = page.locator('textarea, input[type="text"]').first();
        await keywordInput.fill('test-keyword-for-mutation-spec');
        const urlInput = page.locator('input[placeholder*="example"]').first();
        if (await urlInput.isVisible({ timeout: 1000 }).catch(() => false)) {
            await urlInput.fill('https://example.com/test');
        }

        // Click Create Rule
        const createBtn = page.getByRole('button', { name: /Create Rule/ });
        await createBtn.click({ force: true });

        await page.waitForTimeout(500);
        expect(captured, 'store endpoint should have been called').not.toBeNull();
        expect(captured.keyword).toBe('test-keyword-for-mutation-spec');
    });

    test('Apply rule (per-row) fires apply-async endpoint', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/autolink');
        await page.waitForLoadState('networkidle');

        let called = false;
        await page.route('**/cp/linkwise/autolink/apply-async/**', async (route) => {
            called = true;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ ok: true, message: 'started' }),
            });
        });

        // Per-row Apply button text is "Apply (N)" for active rules with
        // would-link count, or "Apply" for inactive ones. Skip if no enabled
        // Apply button — fixture-dependent.
        const applyBtn = page.getByRole('button', { name: /^Apply(\s\(\d+\))?$/ }).first();
        if (!(await applyBtn.isVisible({ timeout: 2000 }).catch(() => false))) {
            test.skip(true, 'No rules in fixture — cannot click per-row Apply');
        }
        if (await applyBtn.isDisabled()) {
            test.skip(true, 'No rule with linkable matches — Apply button disabled');
        }

        await applyBtn.scrollIntoViewIfNeeded();
        await applyBtn.click({ force: true });
        await page.waitForTimeout(500);

        expect(called, 'apply-async endpoint should have been called').toBe(true);
    });

    test('Search filters visible rules', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/autolink');
        await page.waitForLoadState('networkidle');

        const search = page.getByPlaceholder('Search rules...');
        if (!(await search.isVisible({ timeout: 1500 }).catch(() => false))) {
            test.skip(true, 'No rules — search field not rendered');
        }

        await search.fill('xyzz-no-match-asdfqwer');
        await page.waitForTimeout(300);

        // The "rule(s)" counter shows 0
        await expect(page.getByText(/0 rule\(s\)/)).toBeVisible({ timeout: 2000 });
    });
});
