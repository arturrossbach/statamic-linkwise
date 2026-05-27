# Linkwise

**An internal-linking and SEO toolkit for Statamic 6: broken-link checking, entry-level link suggestions, `rel`-attribute control per domain, and bulk URL replacement, all inside the Control Panel.**

❓ **FAQ:** [docs/FAQ.md](docs/FAQ.md)
📝 **Changelog:** [CHANGELOG.md](CHANGELOG.md)
🛒 **Marketplace:** [statamic.com/addons/arturrossbach/linkwise](https://statamic.com/addons/arturrossbach/linkwise)

## Features

- 🔗 **Suggestion engine.** Surfaces inbound and outbound link candidates per entry through title overlap (with language-aware stemming) and editor-defined custom keywords. Prioritises high-signal matches over a long list of noisy ones.
- 🧱 **Indexes nested Replicator fields.** Peak Cards, Bard custom sets, accordions, and any addon that stores text in standard Statamic field structures, so suggestions cover the full body of every entry.
- ⚡ **Auto-Link Rules.** Map a keyword to a URL with case-sensitivity, collection scoping, once-per-post enforcement, per-locale scope, and auto-apply on save. Apply retroactively across existing content with one click.
- 🔍 **Broken-link finder.** Crawls every external link on your site, classifies the failure (timeout, SSL error, connection failure, 4xx, 5xx), and lets you fix, ignore, or unlink it inline. Discovery dates are preserved across re-scans.
- 🔄 **URL Changer.** Bulk-replace URLs across the entire site with locale-restricted apply. Conflict-safe against concurrent edits: skips entries that another editor modified mid-operation rather than overwriting them.
- 🌐 **Domain Manager.** Set `rel="nofollow"`, `"sponsored"`, or `"ugc"` per external domain. The attribute applies on render to every existing and future link to that domain, with no Bard content rewritten.
- 📊 **Custom Keywords.** Tell Linkwise which topics each entry should be a link target for, beyond what the title contains. Editor-curated keywords take priority over default title-matching, with TF-IDF auto-extraction as reference.
- 📋 **7-tab dashboard.** Overview, Links Report, Broken Links, Domains, Auto-Linking, Custom Keywords, URL Changer. CSV export on Links Report, Broken Links, Domains, and Auto-Linking.

## Installation

```bash
composer require arturrossbach/statamic-linkwise
```

Auto-registers via `AddonServiceProvider`. Open the Control Panel and navigate to **Linkwise**. Click **Scan Content** to build the initial index, or run `php artisan linkwise:index` from the CLI.

## Requirements

- Statamic 6.x
- Laravel 12+
- PHP 8.2+
- `exec()` **and** `proc_open()` enabled (see [Hosting notes](#hosting-notes))

### Hosting notes

Linkwise dispatches long-running operations (Scan Content, Check Links, Bulk Unlink, Apply Rule, URL Changer Apply, Inbound/Outbound Insert) as detached background processes via `exec()`. If your host has blacklisted `exec` or `proc_open` via the PHP `disable_functions` directive, those features silently no-op: the buttons reach the server but the dispatched job never starts.

The Linkwise Control Panel detects this on every page load and shows a visible red banner when the primitives are missing, so you'll know up front instead of debugging a hanging "Scan Content" button. Single-entry actions (creating individual links from the entry editor, custom keywords) keep working without `exec()`.

Cheap shared-hosting plans often disable these PHP functions; managed Statamic-friendly hosts and self-managed VPS / cloud servers normally have them enabled. If you're unsure, check `phpinfo()` for the `disable_functions` directive or ask your provider before deploying.

## Configuration

All settings are configurable in the Control Panel under **Settings > Linkwise**, or via `config/linkwise.php` after `php artisan vendor:publish --tag=linkwise-config`.

## Language support

Linkwise's NLP pipeline (stemming, stopwords, keyword extraction) is language-aware. Three tiers reflect what Linkwise actually does for each language out of the box, derived from objective code-level capability (not marketing claims). If your language is not listed, the runtime falls back to English defaults; verify behaviour with a local scan before licensing.

### Language tiers

**Full pipeline** (stemming, stopwords, and inflected-form matching): English, German, French, Spanish, Italian, Dutch, Portuguese, Swedish, Danish, Norwegian, Finnish, Romanian, Russian, Catalan.

**Stopwords only** (no stemmer, so auto-link matches exact word forms; plurals and conjugations don't fold together): Hungarian, Polish, Czech, Slovak, Slovenian, Croatian, Bulgarian, Ukrainian, Latvian, Lithuanian, Estonian, Irish, Greek, Turkish.

**Not supported**: Arabic, Hebrew, Chinese, Japanese, Korean, Thai, Vietnamese.

The Settings dropdown only lists supported languages, so you cannot accidentally configure something that won't work.

### Multilingual content (V1.2+)

> Multisite is not the same as multilingual. Multisite means multiple Sites in `sites.yaml` (can share a language). Multilingual means content in two or more different languages, typically via Multisite where each Site declares its own `lang:`. The features below kick in when the index carries two or more distinct locales.

On Statamic-multisite installs with multiple content languages, Linkwise auto-detects each entry's content language via `$site->lang()` and:

- **Scopes Suggestions per-site.** A DE-source entry only suggests DE-target entries. EN sources don't surface DE targets, and vice versa.
- **Per-rule locale scoping for Auto-Link.** A rule with `locales: ['de']` fires only on DE entries, even when the keyword appears in EN content as a loanword.
- **URL Changer per-locale option.** Restrict a URL migration to a single site when needed.
- **Locale filters** on Links Report, Broken Links, and locale badges in the Suggestion modals.
- **Inherited title hint.** If a blueprint declares `title: { localizable: false }`, the Links Report shows an `(inherited <code>)` hint so the editor knows the title is the Origin's.

Single-site installs see no UI differences; every multilang surface hides itself when the index has fewer than two distinct locales.

For the full per-tab UX inventory + V1.3 roadmap, see [`docs/POST_MULTILANG_GAPS.md`](docs/POST_MULTILANG_GAPS.md). For the code-level audit, see [`docs/MULTISITE_AUDIT.md`](docs/MULTISITE_AUDIT.md).

## Privacy

All link data lives in `storage/linkwise/` on your server. No telemetry, no analytics, no external service calls. Frontend errors are captured locally to `storage/linkwise/frontend-errors.log` for debugging.

## Reporting issues

Open a [GitHub issue](https://github.com/arturrossbach/statamic-linkwise/issues). The Control Panel's **Help > Download diagnostic ZIP** attaches the privacy-safe runtime info needed for triage.

Commercial support: linkwise.support@gmail.com

## License

Commercial license. See [LICENSE.md](./LICENSE.md). Free in development; the per-installation license activates when you deploy to production. Purchase via the [Statamic Marketplace](https://statamic.com/addons/arturrossbach/linkwise).
