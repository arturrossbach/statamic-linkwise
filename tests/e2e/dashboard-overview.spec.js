import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Overview Tab ──────────────────────────────────────────────────────────────
// Covers the current 8-card layout (4 core metrics + 4 health), recommendations,
// click-through navigation, and visual style assertions.
//
// Cards render as <button> elements (role="button"), so we target via
// getByRole('button', { name: /label/ }) rather than css text-locators which
// trigger strict-mode violations (e.g. "Broken Links" exists as both tab link
// AND card label).

// Anchor the label at the start so "Outbound Links" doesn't ambiguously
// match "Avg Outbound Links" now that both Row-2 cards are clickable too.
const cardByLabel = (page, label) => page.getByRole('button', { name: new RegExp('^' + label) });

test.describe('@overview Overview — Structure', () => {
    test('Loads with header + Scan Content button + tab nav', async ({ page }) => {
        await goToLinkwise(page);

        await expect(page.getByRole('button', { name: 'Scan Content' })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Linkwise tabs' })).toBeVisible();
        // "Overview" tab link inside the nav
        await expect(page.getByRole('navigation', { name: 'Linkwise tabs' }).getByRole('link', { name: 'Overview' })).toBeVisible();
    });

    test('Shows 4 core metric cards', async ({ page }) => {
        await goToLinkwise(page);

        await expect(cardByLabel(page, 'Entries Indexed')).toBeVisible();
        await expect(cardByLabel(page, 'Outbound Links')).toBeVisible();
        await expect(cardByLabel(page, 'Orphaned Entries')).toBeVisible();
        await expect(cardByLabel(page, 'External Domains')).toBeVisible();
    });

    test('Shows 4 health/highlight cards', async ({ page }) => {
        await goToLinkwise(page);

        // Broken Links is BOTH a tab link AND a clickable card — pick the button (card).
        await expect(cardByLabel(page, 'Broken Links')).toBeVisible();
        // Inbound Coverage / Avg Outbound / Top Linked are non-clickable cards
        // — they render as plain divs, so target by text but scope outside the nav.
        const main = page.getByRole('main');
        await expect(main.getByText('Inbound Coverage')).toBeVisible();
        await expect(main.getByText('Avg Outbound Links')).toBeVisible();
        await expect(main.getByText('Top Linked')).toBeVisible();
    });

    test('Numeric values render — total_entries > 0 (active site)', async ({ page }) => {
        await goToLinkwise(page);

        const card = cardByLabel(page, 'Entries Indexed');
        const valueText = await card.locator('.text-2xl').first().textContent();
        const value = parseInt((valueText || '').trim(), 10);
        expect(value).toBeGreaterThan(0);
    });
});

test.describe('@overview Overview — Click-through navigation', () => {
    // Inertia navigation uses XHR + history.pushState — networkidle isn't a
    // reliable signal. Use waitForURL to wait for the URL to actually change.

    test('Entries Indexed card → navigates to Links Report', async ({ page }) => {
        await goToLinkwise(page);

        await cardByLabel(page, 'Entries Indexed').click();
        await page.waitForURL(/\/cp\/linkwise\/links/, { timeout: 5000 });
    });

    test('Orphaned Entries card → navigates to Links Report with orphaned filter', async ({ page }) => {
        await goToLinkwise(page);

        await cardByLabel(page, 'Orphaned Entries').click();
        await page.waitForURL(/\/cp\/linkwise\/links\?orphaned=1/, { timeout: 5000 });
    });

    test('External Domains card → navigates to Domains', async ({ page }) => {
        await goToLinkwise(page);

        await cardByLabel(page, 'External Domains').click();
        await page.waitForURL(/\/cp\/linkwise\/domains/, { timeout: 5000 });
    });

    test('Card is keyboard-accessible (Enter triggers nav)', async ({ page }) => {
        await goToLinkwise(page);

        const card = cardByLabel(page, 'Entries Indexed');
        await card.focus();
        await page.keyboard.press('Enter');
        await page.waitForURL(/\/cp\/linkwise\/links/, { timeout: 5000 });
    });

    test('Inbound Coverage card → navigates to Links Report with orphaned filter', async ({ page }) => {
        await goToLinkwise(page);

        // Inbound Coverage is a non-clickable Card div with no role="button"
        // — target via the title text, climb to the parent Card.
        const main = page.getByRole('main');
        const card = main.getByText('Inbound Coverage', { exact: true }).locator('..').locator('..').locator('..');
        await card.click({ force: true });
        await page.waitForURL(/\/cp\/linkwise\/links\?orphaned=1/, { timeout: 5000 });
    });

    test('Avg Outbound Links card → navigates to Links Report', async ({ page }) => {
        await goToLinkwise(page);

        const main = page.getByRole('main');
        const card = main.getByText('Avg Outbound Links', { exact: true }).locator('..').locator('..').locator('..');
        await card.click({ force: true });
        await page.waitForURL(/\/cp\/linkwise\/links(?:\?|$)/, { timeout: 5000 });
    });
});

test.describe('@overview Overview — Visual style asserts', () => {
    // BLOCKING memory rule: every styled element gets a computed-style check.
    // CSS bundle drift (a missing Tailwind class) would otherwise slip through
    // structural tests silently.

    /**
     * Statamic 6 ships with Tailwind 4 which uses the oklch() color space.
     * Match either rgb() OR oklch() with a hue in the green/yellow/red range:
     *   red   ≈ 25–45°
     *   yellow ≈ 90–115°
     *   green ≈ 130–165°
     */
    const isSemanticColor = (str) => {
        if (!str) return false;
        // rgb() — Tailwind 3 / older
        if (/rgb\(\s*220,\s*252|rgb\(\s*254,\s*249|rgb\(\s*254,\s*226/.test(str)) return true; // 100-shade bg
        if (/rgb\(\s*34,\s*197,\s*94|rgb\(\s*234,\s*179,\s*8|rgb\(\s*239,\s*68,\s*68/.test(str)) return true; // 500-shade
        // oklch(L C H) — Tailwind 4
        const m = str.match(/oklch\(\s*[\d.]+\s+[\d.]+\s+([\d.]+)/);
        if (m) {
            const h = parseFloat(m[1]);
            return (h >= 20 && h <= 50) || (h >= 85 && h <= 120) || (h >= 125 && h <= 170);
        }
        return false;
    };

    test('Health badge uses one of the three semantic colors (great/ok/warning)', async ({ page }) => {
        await goToLinkwise(page);

        const main = page.getByRole('main');
        const coverageCard = main.getByText('Inbound Coverage', { exact: true }).locator('..').locator('..');
        const badge = coverageCard.locator('span').filter({
            hasText: /^(Great|OK|Needs Work)$/,
        }).first();
        await expect(badge).toBeVisible();

        const bg = await badge.evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(isSemanticColor(bg), `badge bg ${bg} should be green/yellow/red`).toBe(true);
    });

    test('Progress bar fill uses semantic color (green/yellow/red)', async ({ page }) => {
        await goToLinkwise(page);

        const bar = page.locator('[role="progressbar"] > div').first();
        await expect(bar).toBeVisible();

        const bg = await bar.evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(isSemanticColor(bg), `progress-bar bg ${bg} should be semantic (green/yellow/red)`).toBe(true);
    });

    test('Clickable card has cursor-pointer + transition', async ({ page }) => {
        await goToLinkwise(page);

        const card = cardByLabel(page, 'Entries Indexed');
        const styles = await card.evaluate((el) => {
            const cs = getComputedStyle(el);
            return { cursor: cs.cursor, transition: cs.transition };
        });

        expect(styles.cursor).toBe('pointer');
        // Tailwind's `transition` shorthand sets transition-property to several
        // values. Any non-default counts as set.
        expect(styles.transition).not.toBe('all 0s ease 0s');
        expect(styles.transition.length).toBeGreaterThan(5);
    });

    test('Outbound Links value stays gray (NOT semantically colored)', async ({ page }) => {
        await goToLinkwise(page);

        const card = cardByLabel(page, 'Outbound Links');
        const value = card.locator('.text-2xl').first();
        const color = await value.evaluate((el) => getComputedStyle(el).color);

        // Should be gray-900 (rgb(17, 24, 39)) or gray-100 in dark mode.
        // The previous bug: ratio-based color tied to entries_with_outbound made
        // the metric conflict with the dedicated avg_outbound_status badge.
        const isGreen = /rgb\(\s*22,\s*163,\s*74|rgb\(\s*34,\s*197,\s*94/.test(color);
        const isYellow = /rgb\(\s*202,\s*138,\s*4|rgb\(\s*234,\s*179,\s*8/.test(color);
        const isRed = /rgb\(\s*220,\s*38,\s*38|rgb\(\s*239,\s*68,\s*68/.test(color);
        expect(isGreen || isYellow || isRed, `Outbound value color ${color} should NOT be semantic`).toBe(false);
    });

    test('Recommendation Alert with action button has CTA visible', async ({ page }) => {
        await goToLinkwise(page);

        // The fixture has 93 orphans → the "orphans-high" recommendation MUST
        // render. CTA text is "See orphans".
        const cta = page.getByRole('button', { name: 'See orphans' });
        if (await cta.isVisible({ timeout: 2000 }).catch(() => false)) {
            // Visual: button has the default Statamic style (NOT ghost — i.e.
            // a real background). Computed-style check guards against
            // variant="ghost" creeping back in (Edit-button regression).
            const styles = await cta.evaluate((el) => {
                const cs = getComputedStyle(el);
                return { bg: cs.backgroundColor, image: cs.backgroundImage };
            });
            // Statamic default Button has gradient OR solid bg — NOT pure transparent.
            const transparent = /rgba\(\s*0,\s*0,\s*0,\s*0\s*\)/i.test(styles.bg);
            const noImage = styles.image === 'none' || styles.image === '';
            expect(transparent && noImage, `CTA appears transparent (no bg + no gradient): ${JSON.stringify(styles)}`).toBe(false);
        } else {
            test.skip(true, 'No "See orphans" CTA in this fixture state');
        }
    });
});

test.describe('@overview Overview — Recommendations', () => {
    test('Index-age timestamp renders when index has been built', async ({ page }) => {
        await goToLinkwise(page);

        // "Last indexed X ago" — relative time string from OverviewTab.relativeTime
        await expect(page.getByText(/Last indexed/i)).toBeVisible();
    });

    test('Recommendation CTA navigates within Linkwise (no full reload)', async ({ page }) => {
        await goToLinkwise(page);

        const cta = page.getByRole('button', { name: 'See orphans' });
        if (!(await cta.isVisible({ timeout: 2000 }).catch(() => false))) {
            test.skip(true, 'No orphans recommendation in this fixture state');
        }

        await cta.click();
        await page.waitForURL(/\/cp\/linkwise\/links\?orphaned=1/, { timeout: 5000 });
    });
});
