import { test, expect } from '@playwright/test';
import { goToLinkwise } from './helpers.js';

// ─── Stale-Check Banner — Cross-Tab Visibility + Behavior ─────────────────────
// Banner appears on every Linkwise tab when the content index is newer than
// the last broken-link check (>5min). One click runs a fresh check; banner
// disappears on next page render.

test.describe('@stalecheck Stale-Check Banner', () => {
    test('staleCheck prop is exposed on every Linkwise page', async ({ page }) => {
        await goToLinkwise(page);

        const staleProp = await page.evaluate(() => {
            // Inertia mounts on a div with [data-page] attribute. Statamic 6
            // uses a non-standard id (not "app") so query by attribute.
            const root = document.querySelector('[data-page]');
            if (!root) return null;
            const data = JSON.parse(root.getAttribute('data-page') || '{}');
            return data?.props?.staleCheck ?? null;
        });

        expect(staleProp, 'staleCheck prop must exist on every page').not.toBeNull();
        expect(staleProp).toHaveProperty('is_stale');
        expect(staleProp).toHaveProperty('check_url');
    });

    test('Banner renders + Run-check button calls check-links endpoint', async ({ page }) => {
        // Force-mock the staleCheck prop to is_stale=true via route interception
        // — independent of fixture state.
        // Inertia's first page load is HTML with the page payload baked into
        // a [data-page] attribute. Patch that attribute to inject is_stale=true
        // so the banner appears regardless of fixture state.
        await page.route('**/cp/linkwise', async (route, request) => {
            if (request.method() !== 'GET') return route.continue();
            const response = await route.fetch();
            const ct = response.headers()['content-type'] || '';
            if (!ct.includes('text/html')) return route.fulfill({ response });
            let html = await response.text();
            html = html.replace(/data-page="([^"]+)"/, (m, encoded) => {
                try {
                    const json = encoded.replace(/&quot;/g, '"');
                    const data = JSON.parse(json);
                    data.props = data.props || {};
                    data.props.staleCheck = {
                        is_stale: true,
                        index_built_at: new Date().toISOString(),
                        broken_last_checked: new Date(Date.now() - 86400000).toISOString(),
                        check_url: '/cp/linkwise/check-links',
                        check_status_url: '/cp/linkwise/check-links/status',
                    };
                    return `data-page="${JSON.stringify(data).replace(/"/g, '&quot;')}"`;
                } catch {
                    return m;
                }
            });
            await route.fulfill({
                status: response.status(),
                headers: response.headers(),
                body: html,
            });
        });

        let checkCalled = false;
        await page.route('**/cp/linkwise/check-links', async (route) => {
            checkCalled = true;
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ ok: true, message: 'started' }),
            });
        });

        await goToLinkwise(page);

        // Banner visible — title text scoped via getByText
        await expect(page.getByText(/Recent edits may have introduced/i)).toBeVisible({ timeout: 3000 });

        const runBtn = page.getByRole('button', { name: 'Run check now' });
        await expect(runBtn).toBeVisible();
        await runBtn.click({ force: true });

        await page.waitForTimeout(500);
        expect(checkCalled, 'check-links endpoint should have been called').toBe(true);
    });

    test('Dismiss button hides banner without firing endpoint', async ({ page }) => {
        // Inertia's first page load is HTML with the page payload baked into
        // a [data-page] attribute. Patch that attribute to inject is_stale=true
        // so the banner appears regardless of fixture state.
        await page.route('**/cp/linkwise', async (route, request) => {
            if (request.method() !== 'GET') return route.continue();
            const response = await route.fetch();
            const ct = response.headers()['content-type'] || '';
            if (!ct.includes('text/html')) return route.fulfill({ response });
            let html = await response.text();
            html = html.replace(/data-page="([^"]+)"/, (m, encoded) => {
                try {
                    const json = encoded.replace(/&quot;/g, '"');
                    const data = JSON.parse(json);
                    data.props = data.props || {};
                    data.props.staleCheck = {
                        is_stale: true,
                        index_built_at: new Date().toISOString(),
                        broken_last_checked: new Date(Date.now() - 86400000).toISOString(),
                        check_url: '/cp/linkwise/check-links',
                        check_status_url: '/cp/linkwise/check-links/status',
                    };
                    return `data-page="${JSON.stringify(data).replace(/"/g, '&quot;')}"`;
                } catch {
                    return m;
                }
            });
            await route.fulfill({
                status: response.status(),
                headers: response.headers(),
                body: html,
            });
        });

        await goToLinkwise(page);

        const banner = page.getByText(/Recent edits may have introduced/i);
        await expect(banner).toBeVisible({ timeout: 3000 });

        await page.getByRole('button', { name: 'Dismiss' }).first().click({ force: true });
        await expect(banner).toBeHidden({ timeout: 2000 });
    });
});
