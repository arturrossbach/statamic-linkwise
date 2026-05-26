# Linkwise

**The internal linking assistant Statamic editors actually use.** Find broken links, surface internal-link opportunities, manage `rel`-attribute governance per domain, and bulk-replace URLs across your entire site — without leaving the Control Panel.

Built for **Statamic 6** with Inertia + native UI components. No external services. No telemetry. Your link data stays on your server.

❓ **FAQ:** [docs/FAQ.md](docs/FAQ.md) — common questions answered up front
📝 **Release notes:** [CHANGELOG.md](CHANGELOG.md) — what changed in each version
🛒 **Marketplace:** [statamic.com/addons/arturrossbach/linkwise](https://statamic.com/addons/arturrossbach/linkwise)

---

## Features

- **🔗 Inbound + Outbound Suggestions** — Suggestion engine that finds linking opportunities through title matches (with stemming), editor-defined custom keywords, and auto-extracted TF-IDF keywords from content.
- **🧱 Works with Peak and other Replicator-based addons** — Indexes content in Peak Cards, Bard custom sets, accordions, and other fieldtypes that store text in standard Statamic field structures.
- **⚡ Auto-Linking** — Keyword → URL rules with case-sensitivity, collection scoping, once-per-post enforcement, and auto-apply-on-save.
- **🔍 Broken-Link Finder** — Smart retry + error classification, inline edit / ignore / unlink from the table.
- **🔄 URL Changer** — Bulk URL replacement that's safe against concurrent edits.
- **🌐 Domain Manager** — Set `rel="nofollow"`, `"sponsored"`, `"ugc"` per external domain. Applied automatically to every link.
- **📊 Target Keywords** — Auto-extracted content keywords + custom keywords per entry, with an exclude block-list.
- **📋 Dashboard** — 7 tabs: Overview, Links Report, Broken Links, Domains, Auto-Linking, Target Keywords, URL Changer. CSV export on Links Report, Broken Links, Domains, and Auto-Linking.

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

Cheap shared-hosting plans often disable these PHP functions; managed Statamic-friendly hosts and self-managed VPS / cloud servers normally have them enabled. If you're unsure, check `phpinfo()` for the `disable_functions` directive or ask your provider before deploying.

---

## Try it before you buy it

Linkwise follows Statamic's standard licensing model: **free in
development, paid in production**. You don't need a license to install
Linkwise locally, point it at your actual content, and verify that

- the NLP pipeline handles your language correctly,
- auto-link rules find the matches you'd expect,
- suggestions feel relevant for your editorial workflow,
- performance is acceptable on your corpus size.

Only when you're ready to deploy on a public site does the per-installation
license activate. **One Linkwise license covers one Statamic installation
— regardless of how many languages, sub-domains, or production domains
that installation serves.** Test locally first; the language compatibility
matrix below tells you which kind of behaviour to expect.

---

## Language support

Linkwise's NLP pipeline (stemming, stop-words, keyword extraction) is
language-aware. Three tiers reflect what Linkwise actually does for each
language out of the box, derived from objective code-level capability —
not marketing claims. If your language is not in this list, the runtime
falls back to English defaults; you can verify behaviour with a local
scan before licensing.

### Multilingual content (V1.2+)

> _Multisite ≠ multilingual. Multisite means multiple Sites in `sites.yaml` (can all share a language for multi-domain setups). Multilingual means content in ≥2 different languages — typically via Multisite where each Site declares its own `lang:`. The features below kick in when the index actually carries ≥2 distinct locales._

On Statamic-multisite installs with multiple content languages, Linkwise auto-detects each entry's
content language via `$site->lang()` and:

- **Scopes Suggestions per-site.** A DE-source entry only suggests DE-target entries. EN sources don't surface DE targets and vice versa. Auto-routing of `statamic://entry::<uuid>` to the current-site localization is handled by Statamic core; Linkwise's filter prevents cross-locale suggestions from ever being generated.
- **Per-rule locale scoping for Auto-Link.** A rule with `locales: ['de']` fires only on DE entries, even when the keyword appears in EN content as a loanword. Leave the locales empty to keep today's "all sites" behavior.
- **URL Changer per-locale option.** Restrict a domain migration to a single site when needed.
- **Locale filters** on Links Report, Broken Links, and locale badges in the Suggestion modals.
- **Editor sees inherited titles.** If a blueprint declares `title: { localizable: false }`, the Links Report shows an "(inherited <code>)" hint so the editor knows the title is the Origin's.

Single-site installs see **no UI differences** — every multilang surface hides itself when the index has fewer than two distinct locales.

For the full per-tab UX inventory + V1.3 roadmap, see [`docs/POST_MULTILANG_GAPS.md`](docs/POST_MULTILANG_GAPS.md). For the code-level audit + design rationale, see [`docs/MULTISITE_AUDIT.md`](docs/MULTISITE_AUDIT.md).

### Language tiers

**Full pipeline** — stemming, stopwords, and inflected-form matching: English, German, French, Spanish, Italian, Dutch, Portuguese, Swedish, Danish, Norwegian, Finnish, Romanian, Russian, Catalan.

**Stopwords only** — no stemmer, so auto-link matches exact word forms (plurals and conjugations don't fold together): Hungarian, Polish, Czech, Slovak, Slovenian, Croatian, Bulgarian, Ukrainian, Latvian, Lithuanian, Estonian, Irish, Greek, Turkish.

**Not currently supported**: Arabic, Hebrew, Chinese, Japanese, Korean, Thai, Vietnamese.

The Settings UI only lists supported languages, so you cannot accidentally configure something that won't work.

---

## Configuration

All settings are configurable via the Statamic CP under **Settings → Linkwise**, or via `config/linkwise.php` after `php artisan vendor:publish --tag=linkwise-config`.

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

For commercial support: linkwise.support@gmail.com

---

## License

Commercial license — see [LICENSE.md](./LICENSE.md).
Purchase via the [Statamic Marketplace](https://statamic.com/addons).
