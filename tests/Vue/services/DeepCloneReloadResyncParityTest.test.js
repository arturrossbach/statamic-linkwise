import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, join } from 'node:path';

/**
 * Structural pin for [[architectural_health]] Klasse 10
 * (deep-clone-prop-stale-after-reload).
 *
 * ## Why this test exists
 *
 * Multiple dashboard tabs deep-clone a parent Inertia prop into local
 * component state so they can mutate it without hitting the readonly
 * proxy:
 *
 *   data() {
 *     return { rules: JSON.parse(JSON.stringify(this.data?.rules || [])) };
 *   }
 *
 * The deep-clone fires ONCE at mount. Subsequent Inertia partial-reloads
 * (`inertiaRouter.reload({ only: [...] })`) update the prop, but the
 * local state stays at its mount-time snapshot forever. Symptoms range
 * from stale counter displays ("Apply (4)" after 3 of 4 were linked) to
 * cascading bugs (frontend sends stale `content_hash` → backend
 * `verifyHashes` rejects all entries as `modified by another editor`,
 * User-Smoke 2026-05-19).
 *
 * The fix is a `watch:` handler on the parent prop that re-runs the
 * deep-clone whenever the prop updates. `LinksReportTab.vue:608` did
 * this from day one for its `localEntries` deep-clone — the other 3
 * tabs missed it.
 *
 * ## Contract
 *
 * For each `.vue` file under `resources/js/components/dashboard/`:
 *
 *   IF the file contains `JSON.parse(JSON.stringify(this.<prop>?…))`
 *   inside data() returning into a local-state key X,
 *   THEN the file's `watch:` block MUST contain a handler keyed on the
 *   same prop path that re-syncs X.
 *
 * Recognised re-sync forms:
 *   - `'data.rules': { handler(val) { this.rules = ... } }`
 *   - `entries: { handler(val) { this.localEntries = ... } }`
 *   - Any watch entry that contains both the source-prop reference
 *     and a `this.<localKey> =` assignment.
 *
 * ## Why source-grep
 *
 * Same rationale as the other 4 structural pins in this repo (Klassen 4.x,
 * 7-Backend, 7-Frontend, 9a). Behaviour-testing each tab's reload-and-
 * resync flow would need heavy fixture mounts. Source-grep is O(1)
 * maintenance and catches the exact drift class — "add a new tab,
 * copy-paste a deep-clone, forget the watcher".
 */

const REPO_ROOT = resolve(__dirname, '../../..');
const DASHBOARD_DIR = resolve(REPO_ROOT, 'resources/js/components/dashboard');
const PAGES_DIR = resolve(REPO_ROOT, 'resources/js/components/pages');

/**
 * Components/state-keys whose deep-clone is intentionally NOT watched.
 * Add an entry with a justification — the next reviewer needs to know
 * why this state legitimately survives mount-time-only initialisation.
 */
const EXEMPT_DEEP_CLONES = {
    'AutoLinkingTab.vue::rules':
        'Parent AutoLinkPage.vue uses `:key="renderKey"` + watch on '
        + '`autolinkData`/`entries` to bump the key and force re-mount '
        + 'of AutoLinkingTab when Inertia partial-reload updates the '
        + 'props. data() runs fresh on remount → deep-clone is fresh. '
        + 'No watcher needed inside AutoLinkingTab. User-Smoke 2026-05-19 '
        + 'confirmed this is the more reliable pattern for nested-prop '
        + 'updates than a deep-watch — see [[architectural_health]] '
        + 'Klasse 10.',
};

function vueFilesIn(dir) {
    const out = [];
    let entries;
    try {
        entries = readdirSync(dir, { withFileTypes: true });
    } catch {
        return out;
    }
    for (const e of entries) {
        const full = join(dir, e.name);
        if (e.isDirectory()) {
            out.push(...vueFilesIn(full));
        } else if (e.name.endsWith('.vue')) {
            out.push(full);
        }
    }
    return out;
}

/**
 * Extract data() initialisations of the form
 *   <localKey>: JSON.parse(JSON.stringify(this.<sourcePath> [|| …]))
 *
 * Returns array of { localKey, sourcePath } pairs.
 * Source-path can be `data?.rules`, `domains`, `entries`, etc.
 */
function findDeepClones(src) {
    const out = [];
    // Match `<key>: JSON.parse(JSON.stringify(this.<rest>`
    // - localKey is captured before `:`
    // - sourcePath is captured up to the next `||` or `)` or whitespace+`)`
    const re = /(\w+):\s*JSON\.parse\(\s*JSON\.stringify\(\s*this\.([\w.?]+)\s*(?:\|\||\)|\s)/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        out.push({
            localKey: m[1],
            // Strip optional-chaining markers (?) for the watch-handler
            // lookup — Vue's watch path uses bare dot-notation.
            sourcePath: m[2].replace(/\?/g, ''),
        });
    }
    return out;
}

/**
 * Check whether the source contains a watch handler on `sourcePath`
 * that mutates `this.<localKey>`. Naïve string-scan — finds the watch
 * block (if any) and asserts both presence of the path-key and the
 * resync mutation inside it.
 */
function hasResyncWatcher(src, sourcePath, localKey) {
    // Slice the watch: { … } block by paren/brace-balancing.
    // Match `watch:` only at start of line (with leading whitespace) to
    // avoid false-positives on `watch:` inside `//` comments — Options-
    // API watch: is always at top-level component scope.
    const watchMatch = src.match(/^\s*watch\s*:/m);
    if (! watchMatch) return false;
    const watchOpen = watchMatch.index;

    // Find the opening `{` of the watch block.
    const braceOpen = src.indexOf('{', watchOpen);
    if (braceOpen === -1) return false;

    // Walk forward to find the matching `}`.
    let depth = 1;
    let i = braceOpen + 1;
    while (depth > 0 && i < src.length) {
        const ch = src[i];
        if (ch === '{') depth++;
        else if (ch === '}') depth--;
        i++;
    }
    const watchBlock = src.slice(braceOpen, i);

    // The watch key can be quoted (e.g. 'data.rules') or bare (e.g.
    // `entries` for top-level prop). Match either form.
    const quotedKey = `'${sourcePath}'`;
    const doubleQuotedKey = `"${sourcePath}"`;
    // For bare names use a regex with word boundaries to avoid false
    // matches on substrings (`entries` matching `localEntries`).
    const barePattern = new RegExp(`\\b${sourcePath.replace(/\./g, '\\.')}\\s*:`);

    const hasKey = watchBlock.includes(quotedKey)
        || watchBlock.includes(doubleQuotedKey)
        || barePattern.test(watchBlock);
    if (! hasKey) return false;

    // The mutation `this.<localKey> = …` must also be present somewhere
    // in the watch block (loose check — assumes one watch handler per
    // source-path, which is the canonical Vue pattern).
    const mutationPattern = new RegExp(`this\\.${localKey}\\s*=`);
    return mutationPattern.test(watchBlock);
}

describe('Deep-clone prop into data() must have re-sync watcher (Klasse 10)', () => {
    const candidateFiles = [
        ...vueFilesIn(DASHBOARD_DIR),
        ...vueFilesIn(PAGES_DIR),
    ];

    it('every deep-cloned prop has a watch handler that re-syncs on prop update', () => {
        const gaps = [];

        for (const path of candidateFiles) {
            const src = readFileSync(path, 'utf8');
            const componentName = path.split('/').pop();

            for (const { localKey, sourcePath } of findDeepClones(src)) {
                const exemptKey = `${componentName}::${localKey}`;
                if (exemptKey in EXEMPT_DEEP_CLONES) continue;

                // Local-key deep-clones (e.g. `editingRuleSnapshot` deep-
                // clones `this.newRule` not a prop) are also legitimate
                // mount-time snapshots — only flag deep-clones of props,
                // detected via `data?.<name>` (object-prop) or top-level
                // (the bare-prop form, validated by checking the source
                // path doesn't reference a local state field).
                //
                // Filter heuristic: if sourcePath starts with `data.`,
                // or is a bare name AND we can find it in props: { ... },
                // it's a prop deep-clone. Local-state deep-clones
                // (newRule) are exempt — they're snapshot patterns, not
                // prop mirrors.
                const isPropClone = sourcePath.startsWith('data.')
                    || isTopLevelProp(src, sourcePath);
                if (! isPropClone) continue;

                if (! hasResyncWatcher(src, sourcePath, localKey)) {
                    gaps.push(
                        `${componentName}: \`${localKey}\` deep-clones \`this.${sourcePath}\` `
                        + 'in data() but has no `watch` handler that re-syncs it on '
                        + 'prop updates',
                    );
                }
            }
        }

        expect(gaps, gaps.length > 0
            ? 'Klasse-10 (deep-clone-prop-stale-after-reload): the following \n'
              + 'components deep-clone a parent prop into local state at mount \n'
              + 'time but never re-sync when the prop updates via Inertia partial- \n'
              + 'reload. Local state stays at mount-snapshot forever. Sites:\n  - '
              + gaps.join('\n  - ')
              + '\n\nFix: add a `watch:` handler (canonical shape — see\n'
              + 'LinksReportTab.vue:608):\n'
              + '  watch: {\n'
              + '    \'data.<propname>\': {\n'
              + '      deep: true,\n'
              + '      handler(val) {\n'
              + '        this.<localKey> = JSON.parse(JSON.stringify(val || []));\n'
              + '      },\n'
              + '    },\n'
              + '  }\n'
              + 'Or add the file::key to EXEMPT_DEEP_CLONES with a written\n'
              + 'justification if the state is intentionally mount-time only.'
            : 'OK').toEqual([]);
    });

    it('sanity: 4 known-good components still satisfy the contract', () => {
        // Guard against the regex short-circuiting silently (path rename,
        // method rename). All 4 components were verified compliant in
        // the PR that introduced this test.
        // AutoLinkingTab uses the `:key="renderKey"` remount pattern
        // from its parent (AutoLinkPage), not an internal watcher — so
        // it's exempt above and NOT in this sanity-check list.
        const checks = [
            { file: 'dashboard/LinksReportTab.vue', localKey: 'localEntries', source: 'entries' },
            { file: 'dashboard/BrokenLinksTab.vue', localKey: 'localLinks', source: 'data.broken_links' },
            { file: 'dashboard/DomainsTab.vue', localKey: 'localDomains', source: 'domains' },
        ];

        for (const { file, localKey, source } of checks) {
            const path = resolve(REPO_ROOT, 'resources/js/components/' + file);
            const src = readFileSync(path, 'utf8');
            expect(hasResyncWatcher(src, source, localKey), file
                + `: watch handler for source=\`${source}\` / mutation=\`this.${localKey}\` not found.`
                + ' Either the file was refactored and this list is stale, or the regex/path drifted.').toBe(true);
        }
    });
});

/**
 * Check whether `name` is declared in the file's props: { ... } block.
 * Used to discriminate prop deep-clones (must have watcher) from local-
 * state deep-clones (snapshot pattern, exempt).
 */
function isTopLevelProp(src, name) {
    const propsOpen = src.indexOf('props:');
    if (propsOpen === -1) return false;

    const braceOpen = src.indexOf('{', propsOpen);
    if (braceOpen === -1) return false;

    let depth = 1;
    let i = braceOpen + 1;
    while (depth > 0 && i < src.length) {
        const ch = src[i];
        if (ch === '{') depth++;
        else if (ch === '}') depth--;
        i++;
    }
    const propsBlock = src.slice(braceOpen, i);

    return new RegExp(`\\b${name}\\s*:`).test(propsBlock);
}
