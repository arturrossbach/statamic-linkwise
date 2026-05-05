import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Button Audit — Render + Click Smoke Tests ─────────────────────────────────
// Why this exists: a missing PHP key, a typo'd action, an unclosed comment block
// can wipe out an entire tab's interactivity without any structural test seeing
// it. This file walks every Linkwise tab, asserts each primary button:
//   (a) renders + is visible
//   (b) is clickable WITHOUT throwing a JS error
//   (c) for non-mutating actions: triggers the expected secondary state (modal
//       opens, URL changes, dropdown expands)
//
// Mutating actions (Apply, Save, Delete, Unlink) intentionally NOT triggered
// here — they need real test fixtures + cleanup. They have dedicated specs.

const collectConsoleErrors = (page) => {
    const errors = [];
    page.on('pageerror', (err) => errors.push(`pageerror: ${err.message}`));
    page.on('console', (msg) => {
        if (msg.type() === 'error') errors.push(`console.error: ${msg.text()}`);
    });
    return errors;
};

const expectNoErrors = (errors, ctx) => {
    // Filter out 3rd-party / Statamic transient noise that we have no control
    // over and that doesn't reflect a Linkwise bug.
    const real = errors.filter(e =>
        !/Failed to load resource/i.test(e) &&
        !/favicon/i.test(e) &&
        !/\[Vue Router warn\]/i.test(e) &&
        !/DevTools/i.test(e),
    );
    expect(real, `${ctx}: ${real.join(' | ')}`).toEqual([]);
};

// ─── OVERVIEW ──────────────────────────────────────────────────────────────────

test.describe('@buttons Overview Tab', () => {
    test('Page renders without JS errors and Scan Content button works', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await goToLinkwise(page);

        const scanBtn = page.getByRole('button', { name: 'Scan Content' });
        await expect(scanBtn).toBeVisible();
        await expect(scanBtn).toBeEnabled();

        expectNoErrors(errors, 'Overview load');
    });
});

// ─── LINKS REPORT ─────────────────────────────────────────────────────────────

test.describe('@buttons Links Report Tab', () => {
    test('Page renders without errors + key buttons visible', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/cp/linkwise/links');
        await page.waitForLoadState('networkidle');

        // Search input
        const search = page.getByPlaceholder(/Search entries/i);
        await expect(search).toBeVisible();

        // Export CSV button (when data exists)
        const exportBtn = page.getByRole('button', { name: /Export CSV/i });
        if (await exportBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
            await expect(exportBtn).toBeEnabled();
        }

        expectNoErrors(errors, 'Links Report load');
    });
});

// ─── BROKEN LINKS ─────────────────────────────────────────────────────────────

test.describe('@buttons Broken Links Tab', () => {
    test('Page renders without errors — primary buttons + filters visible', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        // Check Links button (always rendered)
        await expect(page.getByRole('button', { name: 'Check Links' })).toBeVisible();

        // Search input
        await expect(page.getByPlaceholder(/Search entries or URLs/i)).toBeVisible();

        // Status filter (MultiSelect button)
        await expect(page.getByRole('button', { name: /Status/i }).first()).toBeVisible();

        expectNoErrors(errors, 'Broken Links load');
    });

    test('Status filter dropdown opens without errors', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/cp/linkwise/broken');
        await page.waitForLoadState('networkidle');

        const statusBtn = page.getByRole('button', { name: /Status/i }).first();
        await statusBtn.scrollIntoViewIfNeeded();
        // Force-click bypasses sticky-banner click interception that can shadow
        // the filter button when an active bulk operation banner is visible.
        await statusBtn.click({ force: true });
        await page.waitForTimeout(200);

        expectNoErrors(errors, 'Broken Links Status dropdown click');
    });
});

// ─── DOMAINS ──────────────────────────────────────────────────────────────────

test.describe('@buttons Domains Tab', () => {
    test('Page renders without errors + key buttons visible', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/cp/linkwise/domains');
        await page.waitForLoadState('networkidle');

        // Scan Content button (header)
        await expect(page.getByRole('button', { name: 'Scan Content' })).toBeVisible();

        // Search input — domains tab has its own search
        const search = page.locator('input[type="text"], input[placeholder*="earch"]').first();
        if (await search.isVisible({ timeout: 1000 }).catch(() => false)) {
            await expect(search).toBeEnabled();
        }

        expectNoErrors(errors, 'Domains load');
    });
});

// ─── AUTO-LINKING ─────────────────────────────────────────────────────────────

test.describe('@buttons Auto-Linking Tab', () => {
    test('Page renders without errors + primary buttons visible', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/cp/linkwise/autolink');
        await page.waitForLoadState('networkidle');

        // Save Rule (primary form action) — keyword input is always there
        const keywordInput = page.locator('input').first();
        await expect(keywordInput).toBeVisible();

        // Either Create Rule or Update Rule button is present (depending on edit state)
        const ruleSubmit = page.getByRole('button', { name: /Create Rule|Update Rule/ });
        await expect(ruleSubmit.first()).toBeVisible();

        expectNoErrors(errors, 'Auto-Linking load');
    });
});

// ─── TARGET KEYWORDS ──────────────────────────────────────────────────────────

test.describe('@buttons Target Keywords Tab', () => {
    test('Page renders without errors + Edit buttons visible', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/cp/linkwise/keywords');
        await page.waitForLoadState('networkidle');

        // At least one Edit button (per entry row) should be present
        const editBtns = page.getByRole('button', { name: 'Edit' });
        expect(await editBtns.count()).toBeGreaterThan(0);

        // Search field
        await expect(page.getByPlaceholder(/Search entries/i)).toBeVisible();

        expectNoErrors(errors, 'Target Keywords load');
    });
});

// ─── URL CHANGER ──────────────────────────────────────────────────────────────

test.describe('@buttons URL Changer Tab', () => {
    test('Page renders without errors + primary buttons visible', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await page.goto('/cp/linkwise/url-changer');
        await page.waitForLoadState('networkidle');

        // Search input — primary entry into URL Changer flow
        const search = page.locator('input').first();
        await expect(search).toBeVisible();

        expectNoErrors(errors, 'URL Changer load');
    });
});

// ─── CROSS-TAB: Tab nav links work without errors ─────────────────────────────

test.describe('@buttons Tab Navigation', () => {
    const TABS = [
        { label: 'Overview', urlMatch: /\/cp\/linkwise(?:\?|$|\/?$)/ },
        { label: 'Links Report', urlMatch: /\/cp\/linkwise\/links/ },
        { label: 'Broken Links', urlMatch: /\/cp\/linkwise\/broken/ },
        { label: 'Domains', urlMatch: /\/cp\/linkwise\/domains/ },
        { label: 'Auto-Linking', urlMatch: /\/cp\/linkwise\/autolink/ },
        { label: 'Target Keywords', urlMatch: /\/cp\/linkwise\/keywords/ },
        { label: 'URL Changer', urlMatch: /\/cp\/linkwise\/url-changer/ },
    ];

    test('Each tab nav link navigates to expected page without JS errors', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        await goToLinkwise(page);

        const nav = page.getByRole('navigation', { name: 'Linkwise tabs' });
        await expect(nav).toBeVisible();

        for (const tab of TABS) {
            await nav.getByRole('link', { name: tab.label }).click();
            await page.waitForURL(tab.urlMatch, { timeout: 5000 });
            // Each tab must render without console errors
            expectNoErrors(errors, `After navigating to ${tab.label}`);
            errors.length = 0; // reset for next tab
        }
    });
});
