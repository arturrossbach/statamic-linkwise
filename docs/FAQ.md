# Linkwise — Frequently Asked Questions

Quick answers for common situations. Not finding yours? [Open an issue](https://github.com/arturrossbach/statamic-linkwise/issues) — typical turnaround is < 48 hours.

---

## Getting started

### What does Linkwise actually do?

Five things, inside the Control Panel:
1. **Suggests internal links** based on title matches, custom keywords, and auto-extracted content keywords. Editors review and accept/skip.
2. **Auto-Link Rules** — keyword → URL automation with per-locale scoping, collection limits, once-per-post enforcement.
3. **Broken Link Finder** with retry, fix-from-table, bulk unlink.
4. **URL Changer** — bulk-replace any URL across the site, safe against concurrent edits.
5. **Domain Manager** — set `rel="nofollow"` / `"sponsored"` / `"ugc"` per external domain. Applied automatically at render time.

### Do I need a license to try it?

No. Free in `local` / `development` environments. The license activates only on a public production hostname. Run it against your real content locally and verify language pipeline, suggestion quality, and performance before paying.

### Does it work with the Peak starter kit?

Yes. Linkwise indexes content in Peak Cards, Bard custom sets, accordions, and other addons that store text in nested fields — no Peak-specific config.

---

## Multilingual content (V1.2)

> **Multisite ≠ Multilingual.** Multisite means ≥2 Sites in `sites.yaml` (which can share a language for multi-domain strategy). Multilingual means content in ≥2 different languages. Linkwise's locale features only activate when the index has ≥2 distinct locales.

### Which languages does Linkwise support?

**Confident** (full NLP pipeline — stemmer + stopwords + inflected matching): English, German, French, Spanish, Italian, Dutch, Portuguese, Swedish, Danish, Norwegian, Finnish, Romanian, Russian, Catalan.

**Limited** (stopwords only, exact-match auto-link): Hungarian, Polish, Czech, Slovak, Slovenian, Croatian, Bulgarian, Ukrainian, Latvian, Lithuanian, Estonian, Irish, Greek, Turkish.

**Not supported:** Arabic, Hebrew, Chinese, Japanese, Korean, Thai, Vietnamese — RTL or non-space-tokenization not yet implemented.

See the [README language tiers section](https://github.com/arturrossbach/statamic-linkwise#language-support) for the full matrix.

### How does Linkwise know which language an entry is in?

It reads `$site->lang()` from Statamic. On single-site installs the global **Single-site content language** setting in **Settings → Linkwise** is the fallback. Linkwise does not auto-detect from the entry's text — site config wins.

### My DE-only Auto-Link Rule still fires on EN entries.

Old rules from before V1.2 have an empty `locales: []` which means **match all sites** (back-compat). Edit the rule, set **Limit to languages: de**, save.

### What's the "(inherited en)" hint in Links Report next to a title?

The entry's title is inherited from its Origin in another locale (`title: { localizable: false }` in the blueprint). Linkwise stems with the Origin's language so the suggestion engine doesn't mismatch; the hint just shows you why the displayed title looks foreign.

### Will Linkwise suggest cross-locale links (DE → EN)?

No. The same-locale filter blocks them at generation time. If you need a manual cross-locale link, just create it in Bard — Linkwise won't fight you, it just won't suggest it.

---

## Auto-Linking

### What's the difference between Auto-Link Rules and Suggestions?

**Rules** = automated. "Every time `pricing` appears, link to entry X" — fires on entry save (if enabled) or on demand via "Apply".

**Suggestions** = curated. Linkwise proposes link opportunities; editor reviews each one. Day-to-day editorial tool; Rules are for systematic keyword-to-URL automation.

### Why didn't my rule fire on a particular entry?

Likely reasons in order:
1. **Once-per-post** enabled and already linked → skip.
2. **Locales** mismatch — entry's locale not in `rule.locales`.
3. **Collections** restriction — entry's collection not in `rule.collections`.
4. **Rule is inactive** (toggle in the table).
5. **Case-sensitive** mismatch.
6. **Anchor already linked elsewhere** in the entry → skip (no overwrite).

The Rule's Preview Modal shows `linked_count` + `linked_elsewhere_count` + `not_insertable_count` — those tell you exactly why.

### Will Auto-Apply on Save overwrite existing links?

Never. If the anchor text is already wrapped in a link mark anywhere in the entry, Linkwise skips. Explicit no-silent-overwrite policy.

---

## Suggestions

### Why does Linkwise suggest an entry I don't think is relevant?

Linkwise ranks via three signals: title-phrase match, custom keywords, auto-extracted content keywords (TF-IDF). Check the **Reason** badge in the modal — it tells you which signal fired. Cleanup paths:
- Add the noise terms to **Settings → Custom Stopwords**
- Add the noisy entry's keywords to the per-entry **Custom Excluded Keywords**
- Add the entry to **Settings → Exclude Entries** so it's neither suggested nor suggesting

### Inbound vs. Outbound — what's the difference?

**Inbound**: target's perspective. "Who could link TO me?" — fills orphan pages.

**Outbound**: source's perspective. "What could I link to?" — used while writing.

Both use the same engine; same-locale filter applies to both.

---

## Performance

### How long does Scan Content take?

Depends on entry count and host. A ~700-entry site on a shared host can take **~15 minutes**; smaller sites finish in seconds. Re-runs are faster because token sets are cached per-entry.

### Does Linkwise slow down the public site?

No. All Linkwise work happens in the Control Panel and on entry-save hooks. The published site reads from Statamic's normal Stache; Linkwise only injects the optional `rel`-attributes you configured per domain.

---

## Hosting

### "exec() is disabled" — what does that mean?

Linkwise dispatches long bulks (Scan Content, Apply Rule, URL Changer, Bulk Unlink) as detached background processes via PHP's `exec()` + `proc_open()`. If your host blocked these (`php.ini`'s `disable_functions`), the CP shows a red banner. Single-entry actions still work; bulks won't.

Statamic-friendly hosts (Cloudways, Forge, Ploi, Hetzner, DigitalOcean, AWS, All-Inkl, HostEurope, Strato Hosting Pro) work. Cheap shared tiers (IONOS Basic, Bluehost budget plans) often don't.

### Does Linkwise need Redis or a queue worker?

No. Background jobs use `exec()` directly — no queue worker required. Flat-file storage everywhere.

---

## Troubleshooting

### After upgrading to V1.2 the Overview shows "Multilingual content detected — index needs refresh".

Your persisted index contains records from before V1.2 (no locale stamp). Click **Scan Content** once — every record gets re-stamped with its site's locale. Banner disappears.

### Suggestions modal shows entries that look unrelated.

Two common causes:
1. **TF-IDF mid-frequency junk** — generic filler words like "really", "actually", "various" can survive stopword filtering. Add them to **Settings → Custom Stopwords**.
2. **Pre-V1.2 entries** missing locale stamps. Run Scan Content.

### The Locale-Filter dropdown disappeared.

Either the index now has < 2 distinct locales (hidden by design), or you're on a tab that doesn't filter by locale (Overview, Domains, URL Changer's domain-list — those aggregate cross-site).

---

## Licensing & Pricing

### What does it cost?

One-time payment per Statamic installation. Free in `local` / `development`. See [the Marketplace listing](https://statamic.com/addons/arturrossbach/linkwise) for current pricing.

### How is a "site" defined?

One Statamic installation = one license, regardless of how many locales, sub-domains, or production hostnames that installation serves. A separate Statamic installation (different codebase) needs its own license.

### What happens if my license expires?

Linkwise continues to read existing data and surfaces a license-warning banner in the CP. Bulks still run; no hard block on critical features. Renew to clear the banner.

---

## Data & Privacy

### Does Linkwise call home?

No. Zero telemetry, zero outbound HTTP except:
- License-check at boot (Statamic's standard mechanism)
- Broken-link HEAD requests when you click **Check Links** (going to the URLs in your content, not anywhere else)

### Where does Linkwise store data?

Flat files under `storage/linkwise/`:
- `index.json` — entry index
- `autolink-rules.json` — your Auto-Link Rules
- `bulk-snapshots/*.json` — record of every bulk action

Plus pre-stemmed frequency-word lists in `resources/data/` (read-only, shipped with the addon). Everything stays on your server. Git-friendly if you want to version your link configuration.

### GDPR concerns?

Linkwise processes only the content you've already published in Statamic — same data your editors and Statamic itself work with. No PII collected, no third-party processors. Add Linkwise to your data-flow diagram as "internal content processing" if your DPIA requires it.

---

## V1.2 multilang quick-reference

| Tab | New in V1.2 |
|---|---|
| Overview | Per-locale entry-count chips ("165 en · 10 de · 10 nl") |
| Links Report | Locale filter, locale badge per row, inherited-title hint |
| Broken Links | Locale filter |
| Auto-Linking | Per-rule "Limit to languages" multi-select |
| URL Changer | Locale-scope selector, internal-title display, bare-UUID search |
| Suggestion Modals | Locale badge per row |

---

Last updated: 2026-05-26 (Linkwise v1.2.0).
