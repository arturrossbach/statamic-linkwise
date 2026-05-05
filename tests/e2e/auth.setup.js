import { test as setup, expect } from '@playwright/test';

const AUTH_FILE = 'test-results/.auth/user.json';

setup('authenticate', async ({ page }) => {
    await page.goto('/cp/auth/login');

    // Statamic 6's login form uses custom Vue textbox components without
    // name=/type=/aria-label. The form has exactly two textboxes — first is
    // email, second is password. Use positional indexing as the only stable
    // anchor we have.
    const textboxes = page.getByRole('textbox');
    await textboxes.first().waitFor({ state: 'visible', timeout: 10000 });
    await textboxes.nth(0).fill('admin@test.com');
    await textboxes.nth(1).fill('123qweasd');

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
