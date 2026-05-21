import { test, expect } from '@playwright/test';
import { dismissOverlays } from './helpers.js';

const AUTH_FILE = 'test-results/.auth/user.json';

// Test fixture — picked via tinker because they have exactly 1 occurrence
// of the anchor (so prepending a 2nd creates the ambiguity that triggers
// the bug visibly):
const SOURCE_ID = 'face3717-7b35-4d47-a59e-bb748a6e6bf1';
const SOURCE_TITLE = 'Die versteckten Kosten der Cache-Invalidierung';
const SOURCE_SLUG = 'die-versteckten-kosten-der-cache-invalidierung';
const TARGET_TITLE = 'Wenn Caching zur technischen Schuld wird';
const ANCHOR = 'technische Schulden';

/**
 * Reproduces the user-reported "Modal Outbound suggestions stale data
 * after parallel-edit" bug class:
 *
 *   1. Modal shows suggestion with anchor "technische Schulden"
 *      surrounded by sentence_context X
 *   2. In another tab, source entry gets edited — "technische Schulden. "
 *      prepended to the first paragraph
 *   3. Save → modal still says context X (because cached) but underlying
 *      entry now has 2 occurrences of the anchor
 *   4. Click Add link → 409 modified-toast (correct)
 *   5. Re-open modal — EXPECTATION: refreshed scan shows the new state
 *      ACTUAL: shows the same stale context, BardLinkInserter wraps the
 *      first occurrence (the new one we prepended), NOT the one the
 *      modal hint claims
 *
 * Test asserts the EXPECTATION — fails before the fix, passes after.
 */

test('Bug PoC: outbound suggestion modal shows stale data after parallel-edit', async ({ browser }) => {
    // ── Context A: user with the Outbound-Suggestion modal open ──
    const ctxA = await browser.newContext({ storageState: AUTH_FILE });
    const pageA = await ctxA.newPage();
    await pageA.goto('/cp/linkwise/links');
    await pageA.waitForLoadState('networkidle');
    await dismissOverlays(pageA);

    // Filter to our source row
    const searchBox = pageA.locator('input[type="search"], input[placeholder*="earch"]').first();
    await searchBox.fill('Cache-Invalidierung');
    await pageA.waitForTimeout(500); // debounce
    const sourceRow = pageA.locator(`tr:has-text("${SOURCE_TITLE}")`).first();
    await expect(sourceRow, `Source row "${SOURCE_TITLE}" must be visible`).toBeVisible({ timeout: 10000 });

    // Open Outbound-Suggestion modal: click the green suggestion-count badge
    // (only visible when there ARE outbound suggestions). It's somewhere in
    // the row's cells. Match green text-color or the "+" amber badge.
    // Strategy: find any clickable button inside the row that opens a
    // suggestion modal. Stack-overlay markers from existing tests use
    // [class*="stack"] as the modal selector.
    const suggestButton = sourceRow.locator('button').filter({ hasText: /^\d+$|^add\+\d+$|orphan/i }).last();
    await suggestButton.click();
    await dismissOverlays(pageA);

    // Wait for Stack overlay to render
    const modal = pageA.locator('[class*="stack"]').first();
    await expect(modal, 'Suggestion-Modal must open').toBeVisible({ timeout: 10000 });

    // Find the row in the modal that mentions our anchor.
    const anchorRow = modal.locator(`tr:has-text("${ANCHOR}")`).first();
    await expect(anchorRow, `Suggestion row for "${ANCHOR}" must exist`).toBeVisible({ timeout: 5000 });
    const initialContextText = (await anchorRow.textContent())?.trim().slice(0, 300) ?? '';
    console.log('INITIAL modal row text:', initialContextText);

    // ── Context B: parallel-edit source entry, prepend a 2nd "technische Schulden. " ──
    const ctxB = await browser.newContext({ storageState: AUTH_FILE });
    const pageB = await ctxB.newPage();
    // Statamic CP entry-edit URL pattern: /cp/collections/{collection}/entries/{id}/{slug}
    await pageB.goto(`/cp/collections/journal/entries/${SOURCE_ID}/${SOURCE_SLUG}`);
    await pageB.waitForLoadState('networkidle');
    await dismissOverlays(pageB);

    // Statamic Bard editor — ProseMirror contenteditable
    const proseMirror = pageB.locator('.ProseMirror').first();
    await expect(proseMirror, 'Bard editor must render').toBeVisible({ timeout: 10000 });
    await proseMirror.click();
    // Move caret to start of document
    await pageB.keyboard.press('Control+Home');
    await pageB.keyboard.type(`${ANCHOR}. `);

    // Save (Statamic CP save button)
    const saveBtn = pageB.locator('button:has-text("Save")').first();
    await saveBtn.click();
    // Wait for save toast
    await pageB.waitForTimeout(2500); // pragmatic — Statamic save flush takes a moment
    await ctxB.close();

    // ── Re-open the modal: close it (Escape) + click suggest-button again ──
    // Skipping the Add-link click — bug is observable via modal-state alone:
    // if the re-opened modal still shows the same sentence_context after a
    // parallel edit prepended the anchor, the cached scan was stale.
    await pageA.keyboard.press('Escape');
    await pageA.waitForTimeout(800);
    await suggestButton.click();
    await dismissOverlays(pageA);
    const modalAgain = pageA.locator('[class*="stack"]').first();
    await expect(modalAgain).toBeVisible({ timeout: 10000 });
    const anchorRowAgain = modalAgain.locator(`tr:has-text("${ANCHOR}")`).first();
    await expect(anchorRowAgain).toBeVisible({ timeout: 5000 });
    const refreshedContextText = (await anchorRowAgain.textContent())?.trim().slice(0, 300) ?? '';
    console.log('REFRESHED modal row text:', refreshedContextText);

    // ── SYMPTOM 1 ASSERTION (modal state lies) ──
    expect(
        refreshedContextText,
        'SYMPTOM 1: Modal context MUST refresh after parallel-edit (stale)'
    ).not.toBe(initialContextText);

    // ── SYMPTOM 2 (the real damage): user proceeds with Add link after
    // seeing the stale modal — the system wraps the WRONG occurrence
    // (the new one we prepended at start, NOT the one whose context the
    // modal claimed to show).
    //
    // Vue checkbox needs a real change-event dispatch — direct .check()
    // flips DOM state but doesn't notify Vue. Trigger via JS evaluate.
    const checkbox = anchorRowAgain.locator('input[type="checkbox"]').first();
    await checkbox.evaluate(el => {
        el.checked = true;
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await pageA.waitForTimeout(800);

    const addBtn = pageA.locator('button:visible').filter({ hasText: /Add\s+\d+\s+link/i }).first();
    if (await addBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await addBtn.click();
        await pageA.waitForTimeout(5000); // wait for completion

        // Read entry content via Statamic CP API (auth cookie is shared)
        const entryJson = await pageA.request.get(`/api/collections/journal/entries/${SOURCE_ID}`).catch(() => null);
        if (entryJson && entryJson.ok()) {
            const data = await entryJson.json();
            const bardJson = JSON.stringify(data.data?.content ?? '');
            const occurrences = (bardJson.match(new RegExp('"text":"' + ANCHOR + '"', 'g')) || []).length;
            console.log(`SYMPTOM 2: anchor occurrences in bard: ${occurrences}`);

            // Naive position check: split on the anchor; the index of the
            // wrapped one tells us which occurrence got the link mark.
            const linkRegex = new RegExp('"text":"' + ANCHOR + '","marks":\\[\\{"type":"link"', 'g');
            const matches = [...bardJson.matchAll(linkRegex)];
            console.log(`SYMPTOM 2: wrapped occurrences: ${matches.length}`);
            if (matches.length === 1) {
                const wrappedAt = matches[0].index;
                const firstAnchorAt = bardJson.indexOf('"text":"' + ANCHOR + '"');
                const isFirstWrapped = wrappedAt === firstAnchorAt;
                console.log(`SYMPTOM 2: wrapped position is FIRST occurrence? ${isFirstWrapped}`);
                expect(
                    isFirstWrapped,
                    'SYMPTOM 2: link MUST land on the occurrence the modal hinted at (NOT the new prepended one)'
                ).toBe(false);
            }
        } else {
            console.log('Could not read entry via API — SYMPTOM 2 unverified');
        }
    } else {
        console.log('Add button not visible — SYMPTOM 2 unverified');
    }
});
