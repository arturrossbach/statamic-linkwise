export async function goToLinkwise(page) {
    // Inject overlay killer for Statamic trial banners. addInitScript can run
    // before <head>/<html> exists — defer until DOM is ready so we never trip
    // a null.appendChild that bubbles up as a console pageerror and pollutes
    // the no-JS-errors assertions in button-audit.spec.js.
    await page.addInitScript(() => {
        const inject = () => {
            const target = document.head || document.documentElement;
            if (!target) return false;
            if (document.getElementById('pw-overlay-killer')) return true;
            const style = document.createElement('style');
            style.id = 'pw-overlay-killer';
            style.textContent =
                'div.fixed.inset-0[style*="z-index"] { display: none !important; pointer-events: none !important; }';
            target.appendChild(style);
            return true;
        };
        if (!inject()) {
            document.addEventListener('DOMContentLoaded', inject, { once: true });
        }
    });

    await page.goto('/cp/linkwise');
    await page.waitForLoadState('networkidle');
    await dismissOverlays(page);
}

export async function dismissOverlays(page) {
    const snoozeButton = page.locator('button:has-text("Snooze")');
    if (await snoozeButton.isVisible({ timeout: 1000 }).catch(() => false)) {
        await snoozeButton.click();
        await snoozeButton.waitFor({ state: 'hidden' }).catch(() => {});
    }

    // Remove fixed overlays from DOM
    await page.evaluate(() => {
        document.querySelectorAll('.fixed.inset-0').forEach(el => el.remove());
    });
}

export async function clickTab(page, tabName) {
    await dismissOverlays(page);
    const tab = page.locator(`button:has-text("${tabName}")`);
    await tab.click();
    // Wait for tab content to be ready instead of fixed timeout
    await page.waitForLoadState('networkidle');
}
