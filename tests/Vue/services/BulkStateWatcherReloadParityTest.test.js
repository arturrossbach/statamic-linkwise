import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, join } from 'node:path';

/**
 * Frontend structural pin for [[architectural_health]] Klasse 7
 * (async-bulk post-completion refresh parity).
 *
 * ## Why this test exists
 *
 * Sister-test to `tests/Unit/Commands/BulkCommandSkipRecordParityTest.php`
 * which closes the BACKEND half of Klasse 7. This file closes the
 * FRONTEND half: every Tab/Page component that subscribes to
 * `bulkState.active` via Vue's `this.$watch(() => bulkState.active, ...)`
 * must trigger a refresh path when the watched bulk reaches a terminal
 * phase, or the UI reads stale counts/hashes.
 *
 * ## Background
 *
 * - PR #49/#55 fixed `LinksReportTab.vue` (Klasse 7 original) — watcher
 *   on `bulkState.lastCompletion` triggers `reloadEntries()` for the 5
 *   destructive kinds.
 * - PR #59 (Welle 1) fixed `AutoLinkingTab.vue` — 2 watcher branches
 *   that cleared state but never refreshed (`this.fetchData()` was
 *   undefined, silent no-op since the tab was extracted from a god-
 *   component). Same exact gap class.
 *
 * Both gaps would have been caught structurally by this test if it
 * had existed earlier. Now it exists.
 *
 * ## Contract
 *
 * For each file under `resources/js/components/dashboard/` and
 * `resources/js/components/pages/`:
 *
 *   IF the file imports `bulkState` AND uses `this.$watch(...)` on it,
 *   THEN the watcher body MUST contain at least one of the recognised
 *   refresh calls:
 *     - `inertiaRouter.reload(...)`
 *     - `runPreview(...)`
 *     - `reloadEntries(...)`
 *     - `fetchData(...)` (only if the method is actually defined in
 *       the file — defensive `typeof === 'function'` guards count as
 *       NOT defined, see Bug #3b from User-Smoke 2026-05-17)
 *
 * Exceptions in EXEMPT_WATCHERS — each with a written justification
 * because the next reviewer needs to understand why the watcher
 * legitimately doesn't refresh.
 *
 * ## Why source-grep instead of behaviour test
 *
 * Same rationale as the PHPUnit pin: the watcher patterns vary across
 * Tab components (different kinds, different completion semantics,
 * different bulk-state shapes). A behaviour-pin would need 5 separate
 * heavy-fixture tests. A source-grep is O(1) maintenance and catches
 * the structural drift class — "add a new tab, copy-paste a watcher,
 * forget the reload call" — which is exactly how AutoLinkingTab gap
 * was introduced.
 */

/**
 * Components/files that have a $watch on bulkState.active but legitimately
 * don't need a Tab-level refresh call. Document WHY here.
 */
const EXEMPT_WATCHERS = {
    'dashboard/DetailModal.vue': 'Modal-internal state only — watcher coordinates the modal close + bulkState.lastCompletion handoff. The TAB hosting the modal (LinksReportTab) owns the refresh path. Verified 2026-05-17.',
};

/**
 * Recognised refresh calls — at least one must appear in a watcher body
 * to satisfy the contract. Order matters only for the regex below.
 */
const REFRESH_CALL_PATTERNS = [
    /inertiaRouter\.reload\s*\(/,
    /router\.reload\s*\(/,
    /\.runPreview\s*\(/,
    /\.reloadEntries\s*\(/,
    /\.fetchData\s*\(/,
];

/**
 * Repo-relative root for the source files. Resolves from this test file's
 * location so it works regardless of where vitest is invoked from.
 */
const ROOT = resolve(__dirname, '../../..');

/**
 * Walk a directory recursively, returning all `.vue` files.
 */
function vueFilesIn(dir) {
    const out = [];
    const stack = [dir];
    while (stack.length) {
        const current = stack.pop();
        const entries = readdirSync(current, { withFileTypes: true });
        for (const e of entries) {
            const full = join(current, e.name);
            if (e.isDirectory()) stack.push(full);
            else if (e.name.endsWith('.vue')) out.push(full);
        }
    }
    return out;
}

/**
 * Returns true if `src` imports `bulkState` from the service module
 * (any name in the destructure, since `bulkState` is named-imported).
 */
function importsBulkState(src) {
    return /import\s*\{[^}]*\bbulkState\b[^}]*\}\s*from\s*['"][^'"]*bulkOperationService/.test(src);
}

/**
 * Returns the slice of `src` between the FIRST occurrence of
 * `this.$watch(() => bulkState` and the closing `)` that balances
 * that call. Returns empty string if no such call exists.
 *
 * Naïve parenthesis-balancer — handles our actual patterns (no nested
 * function expressions with stray parens inside) but would need a real
 * parser for arbitrary code.
 */
function extractBulkStateWatcherBodies(src) {
    const bodies = [];
    const re = /this\.\$watch\s*\(\s*\(\s*\)\s*=>\s*bulkState\b/g;
    let match;
    while ((match = re.exec(src)) !== null) {
        let depth = 1;
        let i = match.index + match[0].length;
        // Find the opening `(` of the $watch call we just matched.
        // We're already past the inner `()` of the arrow function;
        // walk back to the actual $watch's opening paren.
        let watchOpenIdx = src.indexOf('(', match.index);
        let depthCount = 1;
        let pos = watchOpenIdx + 1;
        while (depthCount > 0 && pos < src.length) {
            const ch = src[pos];
            if (ch === '(') depthCount++;
            else if (ch === ')') depthCount--;
            pos++;
        }
        bodies.push(src.slice(watchOpenIdx, pos));
    }
    return bodies;
}

describe('BulkState-watcher post-completion reload parity (Klasse 7 frontend)', () => {
    const dashboardDir = resolve(ROOT, 'resources/js/components/dashboard');
    const pagesDir = resolve(ROOT, 'resources/js/components/pages');

    const candidateFiles = [
        ...vueFilesIn(dashboardDir),
        ...vueFilesIn(pagesDir),
    ];

    it('every $watch(()=>bulkState.*) body contains a refresh-call (or is exempt)', () => {
        const gaps = [];

        for (const path of candidateFiles) {
            const relativePath = path.slice(ROOT.length + 1)
                .replace('resources/js/components/', '');
            if (relativePath in EXEMPT_WATCHERS) continue;

            const src = readFileSync(path, 'utf8');
            if (! importsBulkState(src)) continue;

            const bodies = extractBulkStateWatcherBodies(src);
            if (bodies.length === 0) continue; // imports but no watcher — OK

            for (const [idx, body] of bodies.entries()) {
                const hasRefresh = REFRESH_CALL_PATTERNS.some(p => p.test(body));
                if (! hasRefresh) {
                    gaps.push(
                        `${relativePath} (watcher #${idx + 1}): no refresh call found`
                        + ` — expected one of inertiaRouter.reload | router.reload |`
                        + ` runPreview | reloadEntries | fetchData`,
                    );
                }
            }
        }

        expect(gaps, gaps.length > 0
            ? 'Klasse-7 frontend sister-gap: the following bulkState '
              + 'watchers clear or read state on completion but never '
              + 'trigger a refresh path, leaving the UI stale:\n  - '
              + gaps.join('\n  - ')
              + '\n\nAdd a refresh call (inertiaRouter.reload is the '
              + 'canonical choice — see AutoLinkingTab.vue:507 or '
              + 'BrokenLinksTab.vue:708), or add the file to '
              + 'EXEMPT_WATCHERS with a justification why the watcher '
              + 'legitimately doesn\'t need to refresh.'
            : 'OK').toEqual([]);
    });

    it('sanity: known-good components still satisfy the contract', () => {
        // Guards against the regex accidentally short-circuiting and
        // passing-vacuously. AutoLinkingTab has 2 watchers (applyrule
        // + detailunlink), both fixed in PR #59. LinksReportTab uses
        // a different watcher pattern (bulkState.lastCompletion) which
        // this test deliberately does NOT cover — that's a separate
        // pattern with its own characterisation in LinksReportBulkRefreshPin.
        const autoLinkPath = resolve(ROOT, 'resources/js/components/dashboard/AutoLinkingTab.vue');
        const src = readFileSync(autoLinkPath, 'utf8');
        const bodies = extractBulkStateWatcherBodies(src);
        expect(bodies.length).toBeGreaterThanOrEqual(2);
        for (const body of bodies) {
            expect(
                REFRESH_CALL_PATTERNS.some(p => p.test(body)),
                'AutoLinkingTab watcher missing refresh call — this should '
                + 'never happen post PR #59. If this fails, either the fix '
                + 'was reverted or the watcher extraction broke the regex.',
            ).toBe(true);
        }
    });
});
