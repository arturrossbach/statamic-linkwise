# Statamic Marketplace Listing — Copy/Paste Source

This is the canonical listing copy for the Statamic Marketplace submission. Paste sections into the corresponding fields when creating/updating the listing at `statamic.com/creator/addons/linkwise`.

---

## Tagline (one line, ~80 chars)

The internal linking assistant Statamic editors actually use.

---

## Short Description (1–2 sentences, ~200 chars)

Find broken links, surface internal linking opportunities, manage `rel` attributes per domain, and bulk-replace URLs across your entire site — all inside the Control Panel. Multilingual-ready, fully local, no external services.

---

## Long Description (Marketplace-Listing-Body)

**Linkwise gives editors a complete internal-linking workflow inside the Statamic Control Panel.** No external SaaS. No telemetry. No analytics callbacks. Your link data lives in `storage/linkwise/` on your own server.

### What you get

- **🔗 Smart suggestion engine** — surfaces internal-linking opportunities across title matches, custom keywords, and content similarity. Per-locale scoping on multilingual installs (V1.2+).
- **🧱 Works with Peak + any Replicator setup** — indexes content in Peak Cards, Bard custom sets, accordions, and any addon that stores text in nested fields.
- **⚡ Auto-Link Rules** — keyword → URL with case-sensitivity, collection scoping, once-per-post enforcement, per-locale scope, and auto-apply-on-save.
- **🔍 Broken-Link Finder** — smart retry + error classification, inline edit / ignore / unlink from the table.
- **🔄 URL Changer** — bulk URL replacement that's safe against concurrent edits, with locale-restricted apply option.
- **🌐 Domain Manager** — set `rel="nofollow"`, `"sponsored"`, `"ugc"` per external domain. Applied automatically to every link.
- **📊 Target Keywords** — auto-extracted content keywords + custom keywords per entry, with exclude block-list.
- **📋 7-Tab Dashboard** — Overview, Links Report, Broken Links, Domains, Auto-Linking, Target Keywords, URL Changer. CSV export everywhere.

### Built for Statamic 6

Inertia + native UI components, fully Statamic-native. No styling weirdness, no shadow DOM, no third-party widgets.

### Multilingual support (V1.2+)

Statamic-multisite installs with multiple content languages get per-locale suggestion scoping, per-rule locale filtering, and auto-detected entry languages out of the box. Works for path-based (`foo.com/de/...`), subdomain (`de.foo.com`), and TLD-based (`foo.de`) multilang setups. Same single license — see Pricing below.

Single-site installs see no UI changes; multilang surfaces only activate when ≥2 distinct content languages are detected.

### Privacy

All link data lives in `storage/linkwise/` on **your** server. Nothing is transmitted externally. No telemetry, no analytics, no SaaS callbacks. Frontend errors logged locally to `storage/linkwise/frontend-errors.log`.

---

## Pricing — Plain-Language Explanation

**€ 99 — one-time payment per Statamic installation.**

- **One license covers one Statamic installation** — defined as a single `composer.json`-bound codebase. **Regardless of how many languages, domains, or sub-domains that installation serves.** Multilingual setups don't multiply the cost.
- **Unlimited staging and development copies** are included. Linkwise runs free on `*.test`, `*.local`, dev sub-domains, and IP-based access — Statamic auto-detects non-public hostnames.
- **All updates within V1.x** are included for the lifetime of your license. No renewal, no subscription, no recurring fee.
- **Future major versions** (V2.0+, when relevant) will be offered as separate Marketplace products. Your V1.x license keeps working regardless of whether you purchase a future major.
- **14-day refund** via the Statamic Marketplace.

### When do you need additional licenses?

You need a separate license for each **separate Statamic installation** — typically a different brand, product, or client project on a different codebase. If you're an agency managing more than 5 separate installations, contact us for bulk pricing.

---

## Try Before You Buy

Linkwise follows Statamic's standard licensing model: **free in development, paid in production**. You can install Linkwise locally, point it at your actual content, and verify that:

- the NLP pipeline handles your language correctly,
- auto-link rules find the matches you'd expect,
- suggestions feel relevant for your editorial workflow,
- performance is acceptable on your corpus size.

Only when you're ready to deploy on a public site does the per-installation license activate.

---

## Requirements

- Statamic 6.x
- Laravel 12+
- PHP 8.2+
- `exec()` **and** `proc_open()` enabled (see GitHub README for hosting notes)

The CP shows a clear red banner if `exec`/`proc_open` are disabled — you'll know up front, not after debugging a hanging bulk operation.

---

## Support + License

- **GitHub:** https://github.com/arturrossbach/statamic-linkwise
- **Documentation:** https://linkwise.arturrossbach.de *(deploying)*
- **FAQ:** [docs/FAQ.md](https://github.com/arturrossbach/statamic-linkwise/blob/master/docs/FAQ.md)
- **License terms:** [LICENSE.md](https://github.com/arturrossbach/statamic-linkwise/blob/master/LICENSE.md)
- **Support:** linkwise.support@gmail.com

---

## Screenshot Plan (for upload — 4–5 shots needed)

Recommended sequence (highest impact first):

1. **Overview tab** — full dashboard with metrics + multilang chips visible. Sells the "complete workflow" idea.
2. **Links Report** — table with inbound/outbound counts + locale column. Sells the data depth.
3. **Suggestion Modal** — inbound or outbound modal with locale badges visible. Sells the smart-engine angle.
4. **Auto-Link Rule form** — locales: scope field + collection scope + once-per-post toggle visible. Sells the editorial control angle.
5. **URL Changer preview** — diff table with context column showing real paragraph snippets. Sells the safe-bulk angle.

Each ~1600×1000 px, no real customer data visible (use prose-peak-test fixture entries).
