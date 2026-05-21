import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Domains — Mutating Actions ────────────────────────────────────────────────
// Per-domain rel attribute select fires the save endpoint with the new value.
// Idempotent (saving same value is a no-op) → safe to test on real backend
// without disturbing fixture, BUT we still mock to keep tests deterministic.

test.describe('@mutations Domains — Action wiring', () => {
    test('Changing per-domain attribute fires save endpoint', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/domains');
        await page.waitForLoadState('networkidle');

        let captured = null;
        await page.route('**/cp/linkwise/domain-attribute', async (route) => {
            captured = await route.request().postDataJSON();
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ ok: true }),
            });
        });

        // First per-domain attribute <select> in table body
        const select = page.locator('tbody select').first();
        if (!(await select.isVisible({ timeout: 2000 }).catch(() => false))) {
            test.skip(true, 'No domains in fixture');
        }

        // Pick a different value than current (simplest: nofollow, but if already nofollow, switch to default)
        const current = await select.inputValue();
        const target = current === 'nofollow' ? 'default' : 'nofollow';
        await select.selectOption(target);

        await page.waitForTimeout(500);
        expect(captured, 'domain-attribute endpoint should have been called').not.toBeNull();
        expect(captured.attribute, 'payload should include the new attribute').toBe(target);
        expect(captured.domain, 'payload should include domain').toBeDefined();
    });

    test('Domain detail link opens detail modal', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/domains');
        await page.waitForLoadState('networkidle');

        // Click a post-count button (only renders if post_count > 0)
        const detailBtn = page.locator('tbody button').filter({ hasText: /^\d+$/ }).first();
        if (!(await detailBtn.isVisible({ timeout: 2000 }).catch(() => false))) {
            test.skip(true, 'No domains with post_count > 0 in fixture');
        }

        await detailBtn.click({ force: true });
        // Modal should open — Statamic uses Stack with title containing domain name
        await page.waitForTimeout(300);
        const modal = page.locator('[role="dialog"], .stack, [class*="stack"]').first();
        await expect(modal).toBeVisible({ timeout: 2000 });
    });
});
