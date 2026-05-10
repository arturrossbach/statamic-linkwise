import { test as setup, expect } from '@playwright/test';

const AUTH_FILE = 'test-results/.auth/user.json';

setup('authenticate', async ({ page }) => {
    await page.goto('/cp/auth/login');

    // Statamic 6's login form: previous positional .nth(1).fill() collapsed
    // both values into the email box (whole "admin@test.com123qweasd" landed
    // in one box, password box stayed empty). Now click each box explicitly
    // before fill so focus is established, and wait for BOTH textboxes to
    // exist so the password box isn't skipped before it renders.
    const emailBox = page.getByRole('textbox').nth(0);
    const passwordBox = page.getByRole('textbox').nth(1);
    await emailBox.waitFor({ state: 'visible', timeout: 10000 });
    await passwordBox.waitFor({ state: 'visible', timeout: 10000 });
    await emailBox.click();
    await emailBox.fill('admin@test.com');
    await passwordBox.click();
    await passwordBox.fill('123qweasd');

    await page.click('button:has-text("Continue")');

    // Wait until we're past the login page
    await expect(page).not.toHaveURL(/auth\/login/, { timeout: 10000 });

    // Dismiss trial overlay if present
    const snoozeButton = page.locator('button:has-text("Snooze")');
    if (await snoozeButton.isVisible({ timeout: 2000 }).catch(() => false)) {
        await snoozeButton.click();
    }

    // Save signed-in state
    await page.context().storageState({ path: AUTH_FILE });
});
