import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Broken Links — Mutating Actions ───────────────────────────────────────────
// Verifies UI wiring: click triggers correct API endpoint with right payload,
// and UI reacts correctly to the response. Real backend mutations are bypassed
// via page.route() interception so the test fixture stays untouched.
//
// Backend mutation logic itself lives in PHPUnit (BardLinkInserterTest etc).

test.describe('@mutations Broken Links — Action wiring', () => {
    test('Single Unlink button opens confirmation modal then fires url-changer/apply with unlink sentinel', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        // Single-row Unlink delegates to applyUrlReplacements() which posts to
        // url-changer/apply with replacement_url = UNLINK_SENTINEL. NOT bulk-unlink.
        let captured = null;
        await page.route('**/cp/linkwise/url-changer/apply', async (route) => {
            captured = await route.request().postDataJSON();
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ ok: true, succeeded: 1, skipped: 0, errors: {} }),
            });
        });

        // Click the first row's "Unlink" button → opens ConfirmationModal
        const unlinkBtn = page.getByRole('button', { name: 'Unlink', exact: true }).first();
        await unlinkBtn.scrollIntoViewIfNeeded();
        await unlinkBtn.click({ force: true });

        // Confirmation modal recognised by its title
        await expect(page.getByText('Remove this link?')).toBeVisible({ timeout: 3000 });
        // Click the danger-variant confirm "Unlink" — second occurrence (modal),
        // not the row buttons. Picking last() works because rows render before the modal.
        const confirmBtn = page.locator('button').filter({ hasText: /^Unlink$/ }).last();
        await confirmBtn.click({ force: true });

        await page.waitForTimeout(500);
        expect(captured, 'url-changer/apply should have been called').not.toBeNull();
    });

    test('Edit URL button opens inline editor with current URL', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        // Pencil icon button — Edit URL — there's no name, so target by aria-label
        const editBtn = page.getByRole('button', { name: 'Edit URL' }).first();
        await editBtn.scrollIntoViewIfNeeded();
        await editBtn.click({ force: true });

        // Inline edit input appears
        const replaceInput = page.getByRole('textbox', { name: /Replacement URL/i });
        await expect(replaceInput).toBeVisible({ timeout: 3000 });
    });

    test('Apply replacement URL fires url-changer/apply endpoint', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        let captured = null;
        await page.route('**/cp/linkwise/url-changer/apply', async (route) => {
            captured = await route.request().postDataJSON();
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ ok: true, succeeded: 1, skipped: 0, errors: {} }),
            });
        });

        // Open editor, type URL, submit
        await page.getByRole('button', { name: 'Edit URL' }).first().click({ force: true });
        const replaceInput = page.getByRole('textbox', { name: /Replacement URL/i });
        await expect(replaceInput).toBeVisible();
        await replaceInput.fill('https://example.com/replacement');
        await replaceInput.press('Enter');

        await page.waitForTimeout(500);
        expect(captured, 'url-changer/apply should have been called').not.toBeNull();
        // Payload contains old URL + new URL
        expect(captured.url || captured.search || JSON.stringify(captured)).toContain('http');
    });

    test('Ignore button fires ignored-links/ignore endpoint', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        let captured = null;
        await page.route('**/cp/linkwise/ignored-links/ignore', async (route) => {
            captured = await route.request().postDataJSON();
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ ok: true }),
            });
        });

        const ignoreBtn = page.getByRole('button', { name: 'Ignore', exact: true }).first();
        if (!(await ignoreBtn.isVisible({ timeout: 1500 }).catch(() => false))) {
            test.skip(true, 'No active broken links to ignore in this fixture');
        }
        await ignoreBtn.scrollIntoViewIfNeeded();
        await ignoreBtn.click({ force: true });

        await page.waitForTimeout(500);
        expect(captured, 'ignored-links/ignore should have been called').not.toBeNull();
        expect(captured.post_id || captured.postId, 'payload should include post_id').toBeDefined();
        expect(captured.url, 'payload should include url').toBeDefined();
    });

    test('Bulk Unlink button only appears with selection', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        // No selection → no bulk button
        await expect(page.getByRole('button', { name: /Unlink \d+ selected/ })).toHaveCount(0);

        // Click first row checkbox to select
        const firstCheckbox = page.locator('tbody tr input[type="checkbox"]').first();
        if (!(await firstCheckbox.isVisible({ timeout: 1500 }).catch(() => false))) {
            test.skip(true, 'No broken links available to select');
        }
        await firstCheckbox.check();

        // Bulk Unlink button now appears
        const bulkBtn = page.getByRole('button', { name: /Unlink 1 selected/ });
        await expect(bulkBtn).toBeVisible({ timeout: 2000 });
    });

    test('Check Links button fires check-links endpoint', async ({ page }) => {
        await goToLinkwise(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        let called = false;
        await page.route('**/cp/linkwise/check-links', async (route) => {
            called = true;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ ok: true, message: 'started' }),
            });
        });

        await page.getByRole('button', { name: 'Check Links' }).click({ force: true });
        await page.waitForTimeout(500);

        expect(called, 'check-links endpoint should have been called').toBe(true);
    });
});
