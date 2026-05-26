# Linkwise — Frequently Asked Questions

Quick answers for common situations. Not finding yours? [Open an issue](https://github.com/arturrossbach/statamic-linkwise/issues) — typical turnaround is < 48 hours.

---

## Getting started

### What does Linkwise actually do?

Five things, in one Control Panel tab:
1. **Suggests internal links** based on title matches, custom keywords, and content similarity. Editors review and accept/skip.
2. **Auto-Link Rules** — keyword → URL automation with per-locale scoping, collection limits, once-per-post enforcement.
3. **Broken Link Finder** with retry logic, fix-from-table, bulk unlink.
4. **URL Changer** — bulk-replace any URL across the site, safe against concurrent edits.
5. **Domain Manager** — set `rel="nofollow"` / `"sponsored"` / `"ugc"` per external domain. Applied automatically at render time.

### How long does it take to set up?

- `composer require arturrossbach/statamic-linkwise` → ~30 seconds
- First Scan Content on a ~200-entry site → ~30 seconds
- First useful suggestions visible → immediately after the scan

No database migrations, no external services, no API keys.

### Do I need a license to try it?

No. Free in `local`/`development` environments. The per-site license activates only when you deploy on a public domain. Run it against your real content locally and verify the language pipeline, suggestion quality, and performance match your expectations before paying.

### Does it work with the Peak starter kit?

Yes. Linkwise indexes content in Peak Cards, Bard custom sets, accordions, and any addon that stores text in nested fields — no Peak-specific configuration needed.

---

## Multilingual content (V1.2)

> **Multisite ≠ Multilingual.** Multisite means you have ≥2 Sites in `sites.yaml` (which can all run in the same language — multi-domain strategy). Multilingual means content exists in ≥2 different languages, typically via Multisite where each Site has its own `lang:` declared. Linkwise's locale features only activate when the index has ≥2 distinct locales.


### How does Linkwise know which language an entry is in?

It reads `$site->lang()` from Statamic. That's the `lang:` field (or `shortLocale()` fallback) in `resources/sites.yaml`. On single-site installs the global `Content Language (fallback)` setting in **Settings → Linkwise** is the source. Linkwise does **not** auto-detect from the entry's actual text — site config wins.

### My DE-site has an entry with an English title — Linkwise treats it as German. Bug?

Not a Linkwise bug. The site declares `lang: de`, Linkwise honors it. If you have mixed-language content on a single site, either:
- Move the foreign-language entries to a separate site with the correct `lang:`
- Accept that the suggestion-quality for those entries will be reduced (foreign words don't match the German stemmer/stopwords)

Content-based language auto-detection is on the V2 roadmap.

### Why don't I see a Locale filter on Links Report?

The filter hides automatically when the persisted index has fewer than 2 distinct locales. Common reasons:
- Single-site install (intended)
- Multilingual set up but Scan Content hasn't run since adding the second-language site
- The site you added has zero indexed entries (collection not in `linkwise.collections` config)

Run **Scan Content** and check Overview → "Entries Indexed" — if you see per-locale chips there, the filter will appear on Links Report.

### My DE-only Auto-Link Rule still fires on EN entries. Why?

You probably created the rule before V1.2. Old rules have an empty `locales: []` which means **match all sites** (back-compat). Edit the rule, set **Limit to languages: de** in the form, save. New rules created in V1.2 default to "all sites" too — opt-in to scoping.

### What's the "(inherited en)" hint in Links Report next to a title?

The entry's title is inherited from its Origin in another locale (the blueprint declared `title: { localizable: false }`). Linkwise stems the title with the Origin's language so the SuggestionEngine doesn't mismatch, but the editor sees the inherited text and the hint warns about it. Make the title localizable in the blueprint if the editor needs to translate it.

### Will Linkwise create cross-locale link suggestions (DE → EN)?

No. The same-locale filter rejects them at generation time. The suggestion modals on a DE entry only show DE targets; EN sources won't see DE targets either. If you need a DE entry to link to an EN URL, do it manually in Bard — Linkwise won't fight you, but it won't suggest it.

### Do I have to re-create my Custom Target Keywords for each locale?

Yes (today). Each localization has its own UUID; Custom Keywords are stored per-UUID. The Inbound Suggestion modal correctly scopes to same-locale matches, but the keyword set itself isn't auto-copied. Origin-group inheritance is on the V1.3 roadmap (see `docs/POST_MULTILANG_GAPS.md`).

---

## Auto-Linking

### What's the difference between Auto-Link Rules and Suggestions?

**Rules** = automated. "Every time the word `Datenbank` appears, link to entry X" — fires on entry save (if enabled) or on demand via "Apply".

**Suggestions** = curated. Linkwise analyzes content and proposes link opportunities; editor reviews each one. The Suggestion modal is the day-to-day editorial tool; Rules are for systematic keyword→URL automation.

Both feed the same insert path under the hood. Rules just skip the review step.

### Why didn't my rule fire on a particular entry?

Likely reasons in priority order:
1. **Once-per-post** enabled (default true) → already linked → skip.
2. **Locales** mismatch (V1.2) → entry's locale not in `rule.locales`.
3. **Collections** restriction → entry's collection not in `rule.collections`.
4. **Rule is inactive** (toggle in the table).
5. **Keyword case-sensitive** → entry uses different casing.
6. **Anchor already linked elsewhere** in the same entry → skip (no overwrite).

The Rule's Preview Modal shows `linked_count` + `linked_elsewhere_count` + `not_insertable_count` — those numbers tell you exactly why.

### Will Auto-Apply on Save overwrite existing links?

Never. `BardLinkInserter` is strict: if the anchor text is already wrapped in a link mark anywhere in the entry, it skips. Linkwise has explicit `feedback_no_silent_overwrite` policy.

---

## Suggestions

### Why does Linkwise suggest an entry I don't think is relevant?

Linkwise ranks via three signals: title-phrase match, custom keywords, TF-IDF content similarity. If a suggestion looks weak, check the **Reason** badge in the modal — it tells you which signal fired. Common cleanup paths:
- Add the noise terms to **Settings → Custom Stopwords**
- Add the noisy entry's keywords to the per-entry **Custom Excluded Keywords**
- Add the entry to **Settings → Exclude Entries** so it's neither suggested nor suggesting

### Why doesn't entry X show up as an inbound suggestion target?

In order: `entry_status` filter excludes drafts/unpublished, then `excluded_entries`, then `excluded_collections`, then locale-scope (V1.2), then already-linked-from-source. The Suggestion Engine logs nothing — your fastest debug is to open the Inbound modal for the target and look at why each candidate source is in/out.

### Inbound vs. Outbound — what's the difference?

**Inbound**: open from the target's perspective. "Who could link TO me?" — surfaces source entries whose body contains your title/keywords. Used to fill orphan pages.

**Outbound**: open from the source's perspective. "What could I link to?" — surfaces target entries whose titles/keywords appear in your body. Used while writing.

Both use the same engine; same-locale filter applies to both.

---

## Performance

### How fast is Scan Content?

| Index size | Local (M1 Mac) | Cloudways Basic | Cloudways Pro |
|---|---|---|---|
| 200 entries | ~10s | ~30s | ~15s |
| 700 entries | ~1min | ~15min | ~5min |
| 5000 entries | ~5min | (unverified) | (unverified) |

Bottleneck is per-entry Bard walk + TF-IDF. On large sites, expect minutes-not-seconds. Re-runs are faster — Linkwise caches token sets per-entry and only re-stems on save.

### My Scan Content seems to hang silently.

Linkwise's audit and scan emit progress dots since V1.x — if you see no output for >1 minute, something genuinely stalled. Check `storage/logs/laravel.log` for exceptions. Common causes:
- `exec()` disabled by hosting provider → the Scan dispatched but the background process never started. CP shows a red banner if detected.
- File-permission issue on `storage/linkwise/` → see logs.
- Index file corrupted from a prior crash → delete `storage/linkwise/index.json` and re-run.

### Does Linkwise slow down the public site?

No. All Linkwise's work happens in the Control Panel and on `Entry::saved` hooks. The published site reads from Statamic's normal Stache; Linkwise injects nothing into the rendered HTML except the optional `rel`-attributes you configured per domain.

---

## Hosting

### "exec() is disabled" — what does that mean?

Linkwise dispatches long bulks (Scan, Apply Rule, URL Changer, Bulk Unlink) as detached background processes via PHP's `exec()` + `proc_open()`. If your host blocked these (`php.ini`'s `disable_functions`), the CP shows a red banner on every page. Single-entry actions still work; bulks won't.

Verified working: Cloudways, Forge, Ploi, RunCloud, DigitalOcean, Hetzner, AWS, IONOS Cloud Compute, All-Inkl, HostEurope, Strato (Hosting Pro).

Known restricted: IONOS / 1&1 Basic Webhosting, Bluehost cheap tiers, most US-budget shared hosts.

### Does Linkwise need Redis / a worker queue?

No. Background jobs use `exec()` directly — no queue worker required. Statamic's flat-file Stache is used for storage. If you have Redis configured for `cache` driver, Linkwise picks it up automatically for some optimizations but doesn't depend on it.

---

## Troubleshooting

### Control Panel shows a white screen + "Cannot read 'warnAt'" error.

Statamic session expired. Reload the page; if still white, navigate to `/cp` directly and re-login. Linkwise's frontend is bundled with Statamic's CP — when the auth-middleware drops `sessionExpiry` from the Inertia props, the entire CP crashes on a Vue destructure. Not a Linkwise bug; standard Statamic behavior after long idle.

### After upgrading to V1.2 the Overview shows a "Multilingual content detected — index needs refresh" banner.

That means your persisted index contains records from before V1.2 (no locale stamp). Click **Scan Content** once — the indexer re-stamps every record with its site's locale. Banner disappears.

### Suggestions modal shows entries that look unrelated.

Two likely culprits:
1. **TF-IDF mid-frequency-junk**: keywords like "richtige", "funktioniert" survive the ISO stopword list. The `frequency-stems-*.json` cull handles this for 9 languages out of the box.
2. **Cross-locale leak**: pre-V1.2 entries (no locale stamp) bypass the same-locale filter. Run Scan Content.

### Bulk action says "succeeded: 8" but my entries are gone.

This happened during V1.2 development when the user re-seeded entries mid-bulk. The bulk completed against the old UUIDs which were then overwritten. **Linkwise persists everything to bulk-snapshots before writing** — check `storage/linkwise/bulk-snapshots/`. The snapshot is your forensic record; the apparent disappearance is usually a concurrent indexer/seed run.

### The Locale-Filter dropdown disappeared.

Either the index now has < 2 distinct locales (hidden by design), or you're on a Linkwise page that intentionally doesn't filter by locale (Overview, Domains, URL Changer's domain-list — those aggregate cross-site).

---

## Licensing & Pricing

### What does it cost?

Per-site license. Free in `local` / `development` environments. See [statamic.com/addons/arturrossbach/statamic-linkwise](https://statamic.com/addons/arturrossbach/statamic-linkwise) for current pricing.

### How do I license it on production?

Statamic's standard license flow — your Statamic Pro key covers the addon-license payment. Linkwise reads from Statamic's license context; no separate config needed in `linkwise.php`.

### What happens if my license expires?

Linkwise continues to read existing data and surfaces a yellow license-warning banner in the CP. Bulks still run (no hard block on critical features). Renew to clear the banner.

### Can I use Linkwise in a client project?

Yes. Same per-site license model as Statamic itself. Each production deployment needs its own license; multiple staging/preview environments on subdomains of the same primary domain count as one site.

---

## Data & Privacy

### Does Linkwise call home?

No. Zero telemetry, zero outbound HTTP except:
- License-check at boot (Statamic's standard mechanism)
- Broken-link HEAD requests when you click "Check Links" (going TO the URLs you have in your content, not anywhere else)

### Where does Linkwise store data?

Three flat-file stores under `storage/linkwise/`:
- `index.json` — entry index
- `autolink-rules.json` — your Auto-Link Rules
- `bulk-snapshots/*.json` — forensic record of every bulk action

Plus pre-stemmed frequency-word lists in `resources/data/frequency-stems-*.json` (read-only, shipped with the addon).

Everything stays on your server. Git-friendly if you want to version-control your link configuration.

### GDPR concerns?

Linkwise processes only the content you've already published in Statamic — same data your editors and Statamic itself work with. No PII collected, no third-party processors invoked. Add Linkwise to your data-flow diagram as "internal content processing" if your DPIA requires it.

---

## V1.2 multilang quick-reference

| Tab | New in V1.2 |
|---|---|
| Overview | Per-locale entry-count chips ("165 en · 10 de · 10 nl") |
| Links Report | Locale-filter dropdown, locale badge per row, inherited-title hint |
| Broken Links | Locale-filter dropdown |
| Auto-Linking | Per-rule "Limit to languages" multi-select |
| URL Changer | Locale-scope "Apply to" selector, internal-title display, bare-UUID search |
| Suggestion Modals | Locale badge per row |
| Domains / Settings | "Sprach-agnostisch" hints |

Full design rationale: [`docs/MULTISITE_AUDIT.md`](MULTISITE_AUDIT.md). UX inventory + V1.3 roadmap: [`docs/POST_MULTILANG_GAPS.md`](POST_MULTILANG_GAPS.md).

---

Last updated: 2026-05-26 (Linkwise v1.2.0).
