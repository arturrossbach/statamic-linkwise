# Linkwise

**The internal linking assistant Statamic editors actually use.** Find broken links, surface internal-link opportunities, manage `rel`-attribute governance per domain, and bulk-replace URLs across your entire site — without leaving the Control Panel.

Built for **Statamic 6** with Inertia + native UI components. No external services. No telemetry. Your link data stays on your server.

📖 **Documentation:** [linkwise.arturrossbach.de](https://linkwise.arturrossbach.de) *(deploying)*
🛒 **Marketplace:** [statamic.com/addons/arturrossbach/statamic-linkwise](https://statamic.com/addons/arturrossbach/statamic-linkwise) *(submitting)*

---

## Features

- **🔗 Inbound + Outbound Suggestions** — Smart suggestion engine that finds linking opportunities across title matches, custom keywords, and content similarity.
- **🧱 Works with Peak + any Replicator setup** — Indexes content in Peak Cards, Bard custom sets, accordions, and any addon that stores text in nested fields.
- **⚡ Auto-Linking** — Keyword → URL rules with case-sensitivity, collection scoping, once-per-post enforcement, and auto-apply-on-save.
- **🔍 Broken-Link Finder** — Smart retry + error classification, inline edit / ignore / unlink from the table.
- **🔄 URL Changer** — Bulk URL replacement that's safe against concurrent edits.
- **🌐 Domain Manager** — Set `rel="nofollow"`, `"sponsored"`, `"ugc"` per external domain. Applied automatically to every link.
- **📊 Target Keywords** — Auto-extracted content keywords + custom keywords per entry, with an exclude block-list.
- **📋 Dashboard** — 7 tabs (Overview, Links Report, Broken Links, Domains, Auto-Linking, Target Keywords, URL Changer) with CSV export.

## Installation

```bash
composer require arturrossbach/statamic-linkwise
```

Auto-registers via `AddonServiceProvider`. Open Control Panel → **Linkwise** in the nav. First visit triggers a content scan.

## Requirements

- Statamic 6.x
- Laravel 12+
- PHP 8.2+
- `exec()` **and** `proc_open()` enabled (see hosting notes below)

### Hosting notes

Linkwise dispatches long-running operations (Scan Content, Check Links,
Bulk Unlink, Apply Rule, URL Changer Apply, Inbound/Outbound Insert) as
detached background processes via `exec()`. If your hosting provider
has blacklisted `exec` or `proc_open` via the PHP `disable_functions`
ini directive, those features will silently no-op — the buttons reach
the server but the dispatched job never starts.

The Linkwise CP detects this on every page load and shows a visible
red banner when the primitives are missing, so you'll know up front
instead of debugging a hanging "Scan Content" button. Single-entry
actions (creating individual links from the entry editor, custom
target keywords) continue to work even without `exec()`.

**Verified working:**
- Managed Statamic-friendly hosts: Cloudways, Laravel Forge,
  Ploi, RunCloud, Server Pilot
- Self-managed VPS / cloud servers: DigitalOcean, Hetzner, AWS,
  IONOS Cloud Compute
- German shared hosts that enable `exec`: All-Inkl, HostEurope,
  Strato (Hosting Pro)

**Known restricted (Linkwise CP banner will appear):**
- IONOS / 1&1 Basic Webhosting tariffs
- Bluehost and most US-budget shared hosts with default PHP hardening

---

## Try it before you buy it

Linkwise follows Statamic's standard licensing model: **free in
development, paid in production**. You don't need a license to install
Linkwise locally, point it at your actual content, and verify that

- the NLP pipeline handles your language correctly,
- auto-link rules find the matches you'd expect,
- suggestions feel relevant for your editorial workflow,
- performance is acceptable on your corpus size.

Only when you're ready to deploy on a public site does the per-site
license activate. Test locally first; the language compatibility matrix
below tells you which kind of behaviour to expect.

---

## Language support

Linkwise's NLP pipeline (stemming, stop-words, keyword extraction) is
language-aware. Three tiers reflect what Linkwise actually does for each
language out of the box, derived from objective code-level capability —
not marketing claims. If your language is not in this list, the runtime
falls back to English defaults; you can verify behaviour with a local
scan before licensing.

### Multisite + per-locale scoping (V1.2+)

On Statamic-multisite installs, Linkwise auto-detects each entry's
content language via `$site->lang()` and:

- **Scopes Suggestions per-site.** A DE-source entry only suggests DE-target entries. EN sources don't surface DE targets and vice versa. Auto-routing of `statamic://entry::<uuid>` to the current-site localization is handled by Statamic core; Linkwise's filter prevents cross-locale suggestions from ever being generated.
- **Per-rule locale scoping for Auto-Link.** A rule with `locales: ['de']` fires only on DE entries, even when the keyword appears in EN content as a loanword. Leave the locales empty to keep today's "all sites" behavior.
- **URL Changer per-locale option.** Restrict a domain migration to a single site when needed.
- **Locale filters** on Links Report, Broken Links, and locale badges in the Suggestion modals.
- **Editor sees inherited titles.** If a blueprint declares `title: { localizable: false }`, the Links Report shows an "(inherited <code>)" hint so the editor knows the title is the Origin's.

Single-site installs see **no UI differences** — every multilang surface hides itself when the index has fewer than two distinct locales.

For the full per-tab UX inventory + V1.3 roadmap, see [`docs/POST_MULTILANG_GAPS.md`](docs/POST_MULTILANG_GAPS.md). For the code-level audit + design rationale, see [`docs/MULTISITE_AUDIT.md`](docs/MULTISITE_AUDIT.md).

### Language Quality Tiers

Coordinator-stopwords (the "don't bridge two stems via `und` / `et` / `y`" anchor-quality filter) are explicitly hand-curated for **English + German** and grammar-reference-derived for the other 12 CONFIDENT-tier languages. The latter are not native-speaker validated; if you spot a false-reject ("Linkwise refused an anchor I expected to see") in FR / ES / IT / NL / PT / SV / DA / NO / FI / RO / RU / CA, please [open an issue](https://github.com/arturrossbach/statamic-linkwise/issues) — fixes ship within days.

### Confident (14 languages)

Snowball stemmer + curated [stopwords-iso](https://github.com/stopwords-iso/stopwords-iso)
list + Western sentence punctuation. Auto-link rules canonicalize
inflected forms (`Datenbank` matches `Datenbanken`, `bibliothèque`
matches `bibliothèques`). Equivalent quality to English content.

| Language   | Stemmer       | Stop-words |
|------------|---------------|------------|
| English    | Snowball EN   | 1298       |
| German     | Snowball DE   |  620       |
| French     | Snowball FR   |  691       |
| Spanish    | Snowball ES   |  732       |
| Italian    | Snowball IT   |  632       |
| Dutch      | Snowball NL   |  413       |
| Portuguese | Snowball PT   |  560       |
| Swedish    | Snowball SV   |  418       |
| Danish     | Snowball DA   |  170       |
| Norwegian  | Snowball NO   |  221       |
| Finnish    | Snowball FI   |  847       |
| Romanian   | Snowball RO   |  434       |
| Russian    | Snowball RU   |  559       |
| Catalan    | Snowball CA   | (curated)  |

### Limited (14 languages)

Stop-words list available, but **no Snowball stemmer** (or a known
edge case Linkwise doesn't yet handle). Auto-link is exact-match —
plural and conjugated forms won't match a base-form rule.

| Language        | Reason                                                        |
|-----------------|---------------------------------------------------------------|
| Hungarian       | No Snowball stemmer                                           |
| Polish          | No Snowball stemmer                                           |
| Czech / Slovak  | No Snowball stemmer                                           |
| Slovenian       | No Snowball stemmer                                           |
| Croatian        | No Snowball stemmer                                           |
| Bulgarian       | No Snowball stemmer                                           |
| Ukrainian       | No Snowball stemmer (Cyrillic)                                |
| Latvian / Lithuanian / Estonian / Irish | No Snowball stemmer            |
| Greek           | Greek `;` not yet recognised as a sentence boundary           |
| Turkish         | Dotted/dotless-i lowercase rules not handled                  |

### Not supported

| Language   | Reason                                                                    |
|------------|---------------------------------------------------------------------------|
| Arabic / Hebrew | RTL + tokenization not implemented (V1.1 candidates)                |
| Chinese    | No space-based word boundaries — needs jieba/ICU tokenizer                |
| Japanese   | No space-based word boundaries — needs MeCab                              |
| Korean     | No space-based word boundaries                                            |
| Thai       | No space-based word boundaries                                            |
| Vietnamese | Diacritic-based syllable structure complicates tokenization               |

The Settings UI hard-blocks selecting a "not supported" language so you
can't silently configure something that won't work.

---

## Configuration

All settings are configurable via the Statamic CP under **Settings → Linkwise**, or via `config/linkwise.php` after `php artisan vendor:publish --tag=linkwise-config`.

For production deployment notes (cache driver, server configuration, long-running bulks), see the [documentation](https://linkwise.arturrossbach.de/).

---

## Bulk operations

Apply auto-link rules to thousands of entries, check links for broken status, replace URLs across your whole site. Bulks run in the background with a live progress banner, cancellable, safe against concurrent edits.

---

## Privacy & GDPR

- All link data lives in `storage/linkwise/` on **your** server. Never transmitted.
- No telemetry. No analytics. No SaaS callbacks.
- Frontend errors are captured **locally** to `storage/linkwise/frontend-errors.log`.

---

## Reporting issues

Open a [GitHub issue](https://github.com/arturrossbach/statamic-linkwise/issues). The Control Panel's **Help → Download diagnostic ZIP** attaches the privacy-safe runtime info we need.

For commercial support: see [LICENSE.md](./LICENSE.md).

---

## License

Commercial license — see [LICENSE.md](./LICENSE.md).
Purchase via the [Statamic Marketplace](https://statamic.com/addons).
