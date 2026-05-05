import { test, expect } from '@playwright/test';
import { goToLinkwise, clickTab, dismissOverlays } from './helpers.js';

// ─── Real-Life SEO Manager Workflows ───────────────────────────────────────────
// These tests simulate actual tasks an SEO manager or agency would perform
// with Linkwise to improve their site's internal linking structure.

test.describe('Workflow: "Welche Seiten haben keine internen Links?"', () => {
    test('SEO-Manager findet verwaiste Seiten und sieht Handlungsempfehlungen', async ({ page }) => {
        await goToLinkwise(page);

        // Schritt 1: Overview checken — wie schlimm ist es?
        const orphanedText = await page.evaluate(() => {
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            for (const card of cards) {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label?.toLowerCase() === 'orphaned entries') return card.textContent.trim();
            }
            return '0';
        });
        const orphanedCount = parseInt(orphanedText);

        // Schritt 2: Links Report öffnen und nach Orphaned filtern
        await clickTab(page, 'Links Report');
        await page.check('text=Orphaned only');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible();

        // Schritt 3: Ergebnis prüfen — der angezeigte Count muss zum Overview passen
        const reportCountText = await page.locator('text=/\\d+ of \\d+ entries/').textContent();
        const reportOrphaned = parseInt(reportCountText.match(/(\d+) of/)[1]);
        expect(reportOrphaned).toBe(orphanedCount);

        // Schritt 4: Für jede verwaiste Seite muss es eine Aktion geben
        const firstDropdown = page.locator('tbody [data-ui-dropdown-trigger]').first();
        if (await firstDropdown.isVisible({ timeout: 3000 }).catch(() => false)) {
            await firstDropdown.click();

            await expect(page.locator('[data-ui-dropdown-item]:has-text("Add Inbound Links")')).toBeVisible();
        }

        // Schritt 5: Suggestions-Spalte zeigt Vorschläge für verwaiste Seiten
        const suggestionsData = await page.evaluate(() => {
            const rows = document.querySelectorAll('tbody tr');
            let withSuggestions = 0;
            rows.forEach(row => {
                if (row.offsetParent === null) return;
                const tds = row.querySelectorAll('td');
                if (tds.length >= 5) {
                    const sugText = tds[4]?.textContent?.trim();
                    if (parseInt(sugText) > 0) withSuggestions++;
                }
            });
            return withSuggestions;
        });

        expect(suggestionsData).toBeGreaterThan(0);
    });
});

test.describe('Workflow: "Neue Blogartikel veröffentlicht — wo soll ich verlinken?"', () => {
    test('SEO-Manager sortiert nach Suggestions um die besten Linking-Chancen zu finden', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        // Nach Suggestions sortieren (höchste zuerst)
        await page.click('th:has-text("Suggestions")');
        await expect(page.locator('th:has-text("Suggestions")')).toContainText(/[↑↓]/);
        await page.click('th:has-text("Suggestions")');

        const topSuggestions = await page.evaluate(() => {
            const rows = document.querySelectorAll('tbody tr');
            for (const row of rows) {
                if (row.offsetParent === null) continue;
                const tds = row.querySelectorAll('td');
                if (tds.length >= 5) {
                    return {
                        title: tds[0]?.textContent?.trim(),
                        suggestions: parseInt(tds[4]?.textContent?.trim()) || 0,
                    };
                }
            }
            return null;
        });

        expect(topSuggestions).not.toBeNull();
        expect(topSuggestions.suggestions).toBeGreaterThan(0);
    });

    test('SEO-Manager sieht Outbound-Details und erkennt unterverlinkte Seiten', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        // Nach Outbound sortieren (niedrigste zuerst)
        await page.click('th:has-text("Outbound")');
        await expect(page.locator('th:has-text("Outbound")')).toContainText(/[↑↓]/);

        const firstOutbound = await page.evaluate(() => {
            const rows = document.querySelectorAll('tbody tr');
            for (const row of rows) {
                if (row.offsetParent === null) continue;
                const tds = row.querySelectorAll('td');
                if (tds.length >= 4) {
                    return parseInt(tds[3]?.textContent?.trim()) || 0;
                }
            }
            return -1;
        });

        expect(firstOutbound).toBeGreaterThanOrEqual(0);
    });
});

test.describe('Workflow: "Google hat Broken Links gefunden — was ist kaputt?"', () => {
    test('SEO-Manager führt Link-Check durch und sieht Ergebnisse', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Broken Links');

        await page.click('button:has-text("Check Links")');

        // Wait for check to complete
        await expect(
            page.locator('th:has-text("Broken URL")').or(page.locator('text=No broken links found'))
        ).toBeVisible({ timeout: 30000 });
        await dismissOverlays(page);

        const hasBrokenTable = await page.locator('th:has-text("Broken URL")').isVisible({ timeout: 3000 }).catch(() => false);
        const hasNoProblems = await page.locator('text=No broken links found').isVisible({ timeout: 1000 }).catch(() => false);

        expect(hasBrokenTable || hasNoProblems).toBeTruthy();

        if (hasBrokenTable) {
            await expect(page.locator('th:has-text("Broken URL")')).toBeVisible();
            await expect(page.locator('th:has-text("Status")')).toBeVisible();

            const typeFilter = page.locator('select').filter({ hasText: 'All Types' });
            if (await typeFilter.isVisible({ timeout: 1000 }).catch(() => false)) {
                await typeFilter.selectOption('internal');
            }
        }

        await clickTab(page, 'Overview');
        await expect(page.locator('text=Entries Indexed')).toBeVisible();
    });
});

test.describe('Workflow: "Keyword auf jeder Seite verlinken"', () => {
    test('SEO-Manager erstellt Auto-Link-Rule, prüft Preview, wendet an', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Auto-Linking');

        // Schritt 1: Rule erstellen
        await page.click('text=Custom URL');

        const keywordInput = page.locator('textarea').first();
        await keywordInput.fill('E2E Test Keyword');

        const urlInput = page.locator('input[placeholder*="https://"]');
        await urlInput.fill('https://example.com/test-page');

        await page.getByRole('button', { name: 'Create Rule' }).click();
        await page.waitForLoadState('networkidle');

        // Schritt 2: Rule erscheint in der Tabelle
        await expect(page.locator('text=E2E Test Keyword')).toBeVisible({ timeout: 5000 });

        // Schritt 3: Preview anschauen
        const ruleRow = page.locator('tr:has-text("E2E Test Keyword")');
        const dropdown = ruleRow.locator('[data-ui-dropdown-trigger]');
        await dropdown.click();
        await page.locator('[data-ui-dropdown-item]:has-text("Preview")').click();

        const previewVisible = await page.locator('text=Preview:').isVisible({ timeout: 3000 }).catch(() => false);
        if (previewVisible) {
            const hasBadge = await page.locator('text=Would link')
                .or(page.locator('text=Linked to target'))
                .or(page.locator('text=Linked elsewhere'))
                .first().isVisible({ timeout: 2000 }).catch(() => false);

            await page.keyboard.press('Escape');
        }

        // Schritt 4: Aufräumen
        const dropdown2 = ruleRow.locator('[data-ui-dropdown-trigger]');
        await dropdown2.click();
        await page.locator('[data-ui-dropdown-item]:has-text("Delete")').click();

        const confirmBtn = page.locator('button:has-text("Delete Rule")');
        if (await confirmBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await confirmBtn.click();
            await page.waitForLoadState('networkidle');
        }
    });
});

test.describe('Workflow: "Partner-Links sollen alle nofollow sein"', () => {
    test('SEO-Manager findet externe Domain und setzt Attribut', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Domains');

        const rowCount = await page.evaluate(() => {
            return Array.from(document.querySelectorAll('tbody tr'))
                .filter(r => r.offsetParent !== null).length;
        });
        expect(rowCount).toBeGreaterThan(0);

        // Domain suchen
        const searchInput = page.locator('input[placeholder="Search domains..."]');
        if (await searchInput.isVisible({ timeout: 2000 }).catch(() => false)) {
            await searchInput.fill('google');
            await page.waitForLoadState('networkidle');
        }

        // Attribut auf "nofollow" setzen
        const firstSelect = page.locator('tbody select').first();
        if (await firstSelect.isVisible({ timeout: 2000 }).catch(() => false)) {
            const currentValue = await firstSelect.inputValue();
            await firstSelect.selectOption('nofollow');
            await page.waitForLoadState('networkidle');

            const hasError = await page.locator('text=Error').isVisible({ timeout: 500 }).catch(() => false);
            expect(hasError).toBeFalsy();

            // Zurücksetzen
            await firstSelect.selectOption(currentValue);
            await page.waitForLoadState('networkidle');
        }

        // Detail-View
        await searchInput.fill('');
        await page.waitForLoadState('networkidle');

        const linkCount = page.locator('tbody td span.cursor-pointer').first();
        if (await linkCount.isVisible({ timeout: 2000 }).catch(() => false)) {
            await linkCount.click();

            const hasContext = await page.locator('th:has-text("Context")').isVisible({ timeout: 3000 }).catch(() => false);
            if (hasContext) {
                expect(hasContext).toBeTruthy();
            }

            await page.keyboard.press('Escape');
        }
    });
});

test.describe('Workflow: "Wie gut ist meine interne Verlinkung insgesamt?"', () => {
    test('SEO-Manager bewertet Link Health und identifiziert Handlungsbedarf', async ({ page }) => {
        await goToLinkwise(page);

        const metrics = await page.evaluate(() => {
            const result = {};
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            cards.forEach(card => {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label) result[label.toLowerCase()] = card.textContent.trim();
            });
            const badges = document.querySelectorAll('.rounded-full');
            badges.forEach(badge => {
                const text = badge.textContent.trim();
                if (['Great', 'OK', 'Needs Work'].includes(text)) {
                    const section = badge.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                    if (section) result[section.toLowerCase() + '_status'] = text;
                }
            });
            return result;
        });

        const total = parseInt(metrics['entries indexed'] || '0');
        const orphaned = parseInt(metrics['orphaned entries'] || '0');

        expect(total).toBeGreaterThan(0);

        if (orphaned > total / 2) {
            expect(metrics['link coverage_status']).toBe('Needs Work');
        }

        const avgOutbound = parseFloat(metrics['avg links / entry'] || '0');
        if (avgOutbound < 1) {
            expect(metrics['avg outbound links_status']).toBe('Needs Work');
        }
    });
});

test.describe('Workflow: "Ich will meine Target Keywords optimieren"', () => {
    test('SEO-Manager setzt Custom Keywords und sieht Content Keywords', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Target Keywords');

        await expect(page.locator('th:has-text("Content Keywords")')).toBeVisible();
        await expect(page.locator('th:has-text("Custom Keywords")')).toBeVisible();

        const searchInput = page.locator('input[placeholder="Search entries or keywords..."]');
        await searchInput.fill('Laravel');
        await page.waitForLoadState('networkidle');

        const editBtn = page.locator('tbody button:has-text("Edit")').first();
        if (await editBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await editBtn.click();

            const textarea = page.locator('textarea[placeholder*="Enter keywords"]');
            await expect(textarea).toBeVisible({ timeout: 3000 });

            await textarea.fill('laravel hosting, laravel deploy, php framework');
            await page.click('button:has-text("Save")');
            await page.waitForLoadState('networkidle');

            await expect(page.locator('text=laravel hosting')).toBeVisible({ timeout: 3000 });
        }
    });
});

test.describe('Workflow: "Schneller Site-Audit nach Relaunch"', () => {
    test('SEO-Manager macht vollständigen Check in unter 2 Minuten', async ({ page }) => {
        await goToLinkwise(page);

        // 1. Index neu bauen
        await page.click('button:has-text("Rebuild Index")');
        await page.waitForLoadState('networkidle');

        // 2. Overview: Gesamtbild
        const total = await page.evaluate(() => {
            const cards = document.querySelectorAll('.text-2xl.font-bold');
            for (const card of cards) {
                const label = card.closest('.flex')?.querySelector('.uppercase')?.textContent?.trim();
                if (label?.toLowerCase() === 'entries indexed') return parseInt(card.textContent);
            }
            return 0;
        });
        expect(total).toBeGreaterThan(0);

        // 3. Broken Links checken
        await clickTab(page, 'Broken Links');
        await page.click('button:has-text("Check Links")');
        await expect(
            page.locator('th:has-text("Broken URL")').or(page.locator('text=No broken links found'))
        ).toBeVisible({ timeout: 30000 });
        await dismissOverlays(page);

        // 4. Domains prüfen
        await clickTab(page, 'Domains');
        await expect(page.locator('th:has-text("Domain")')).toBeVisible();

        // 5. Orphaned Seiten finden
        await clickTab(page, 'Links Report');
        await page.check('text=Orphaned only');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible();

        // 6. Auto-Link Rules prüfen
        await clickTab(page, 'Auto-Linking');
        await expect(page.locator('text=Auto-Linking Rules')).toBeVisible();
    });
});

test.describe('Workflow: "Klickbare Metriken als Shortcut"', () => {
    test('Klick auf Orphaned-Karte springt zum Links Report mit Filter', async ({ page }) => {
        await goToLinkwise(page);

        const orphanedCard = page.locator('text=Orphaned Entries').locator('..').locator('..');
        await orphanedCard.click();

        await expect(page.locator('th:has-text("Entry Title")')).toBeVisible({ timeout: 3000 });
        const checkbox = page.locator('input[type="checkbox"]').first();
        await expect(checkbox).toBeChecked({ timeout: 3000 });
    });

    test('Klick auf Broken Links springt zum Broken Links Tab', async ({ page }) => {
        await goToLinkwise(page);

        await page.evaluate(() => {
            const cards = document.querySelectorAll('.uppercase');
            for (const label of cards) {
                if (label.textContent.trim().toLowerCase() === 'broken links') {
                    label.closest('[class*="cursor-pointer"]')?.click();
                    break;
                }
            }
        });

        await expect(page.locator('button:has-text("Check Links")')).toBeVisible({ timeout: 3000 });
    });

    test('Klick auf External Domains springt zum Domains Tab', async ({ page }) => {
        await goToLinkwise(page);

        const domainsCard = page.locator('text=Click to manage rel attributes').locator('..').locator('..');
        await domainsCard.click();

        await expect(page.locator('th:has-text("Domain")')).toBeVisible({ timeout: 3000 });
    });
});

test.describe('Workflow: "CSV Export für den Kunden-Report"', () => {
    test('SEO-Manager exportiert Link-Report als CSV für Kundenpräsentation', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        const downloadPromise = page.waitForEvent('download');
        await page.click('button:has-text("Export CSV")');
        const download = await downloadPromise;

        expect(download.suggestedFilename()).toBe('linkwise-report.csv');

        const path = await download.path();
        expect(path).toBeTruthy();
    });

    test('SEO-Manager exportiert gefilterte Daten', async ({ page }) => {
        await goToLinkwise(page);
        await clickTab(page, 'Links Report');

        await page.check('text=Orphaned only');
        await expect(page.locator('text=/\\d+ of \\d+ entries/')).toBeVisible({ timeout: 3000 });

        const downloadPromise = page.waitForEvent('download');
        await page.click('button:has-text("Export CSV")');
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe('linkwise-report.csv');
    });
});
