# What is Linkwise?

Linkwise is the internal linking assistant for Statamic 6. It runs entirely inside the Control Panel and helps editorial teams do four things that are otherwise tedious or impossible:

1. **Find linking opportunities you'd miss manually** — multi-tier suggestion engine surfaces both inbound (entries that *should* link to this one) and outbound (entries this one *could* link to) candidates with anchor-text editing built in.
2. **Find and fix broken links** — full-site HTTP scan with error-type classification, inline replace/ignore/unlink right from the table.
3. **Bulk-rewrite URLs across your entire site** — smart-match or exact-match modes, optimistic locking against concurrent editors.
4. **Govern external links centrally** — per-domain `rel="nofollow"` / `"sponsored"` / `"ugc"` applied automatically via a Bard mark extension, no content rewriting.

## Who is it for?

- **SEO managers** who want to spot internal-linking gaps without exporting CSVs to a third-party tool.
- **Editorial teams** who need to know which posts have broken links *before* readers do.
- **Migration teams** rebuilding URL structures who don't want to grep through a hundred Bard fields by hand.
- **Statamic developers** shipping content-heavy sites that need defensible internal-linking practices.

## Architecture in one sentence

Linkwise reads every Bard, Replicator, Peak Card, and Markdown/text/textarea field in your collections; builds an index of titles, keywords (TF-IDF + Snowball stemming, EN/DE), and existing internal/external links; and runs a multi-tier matcher to suggest where new links would fit.

All data stays on your server in `storage/linkwise/`. No SaaS callbacks. No telemetry. No external services unless you opt in to BYOK AI for semantic matching.

## What's next?

- **[Installation →](/guide/installation)** — composer require, requirements, first scan
- **[Configuration →](/guide/configuration)** — config file vs. CP settings

::: info More guides coming
Tab-by-tab walkthroughs (Overview, Links Report, Broken Links, Domains, Auto-Linking, Target Keywords, URL Changer), production setup, troubleshooting and the full FAQ are being written. Check back soon — or [open an issue](https://github.com/arturrossbach/statamic-linkwise/issues) if you have a specific question.
:::
