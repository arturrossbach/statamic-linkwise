import { test, expect } from '@playwright/test';

/**
 * Minimal smoke test that proves Playwright is fully wired:
 * authenticated session, navigation, content assertion.
 * Independent of any Linkwise-specific UI selectors that may
 * have drifted since the older e2e specs were written.
 */
test('smoke: authenticated user can reach Linkwise dashboard', async ({ page }) => {
    await page.goto('/cp/linkwise');
    await expect(page).toHaveURL(/\/cp\/linkwise/, { timeout: 10000 });
    await expect(page.locator('text=Linkwise').first()).toBeVisible({ timeout: 10000 });
});

test('smoke: links report endpoint loads + has at least one entry row', async ({ page }) => {
    await page.goto('/cp/linkwise/links');
    await page.waitForLoadState('networkidle');
    // Any tr in any tbody — proves the index loaded
    const rows = page.locator('tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 10000 });
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
});

test('smoke: broken-links page loads', async ({ page }) => {
    await page.goto('/cp/linkwise/broken');
    await expect(page).toHaveURL(/\/broken/, { timeout: 10000 });
    await page.waitForLoadState('networkidle');
});
