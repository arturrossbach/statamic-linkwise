# Multilingual

On a multisite Statamic install, Linkwise is locale-aware throughout: it detects
each entry's language, keeps suggestions within a language, and scopes its
reports per locale.

## What it does

It stops cross-language noise. A German entry only ever suggests links to other
German entries; an English page is graded against English content. Reports and
stats can be narrowed to one language so editors see only what's relevant to them.

## How it works

- **Per-entry language detection.** Language is taken from each Statamic site's
  `lang` (`de_DE` → German), so stemming and stop-words apply correctly per
  entry. You don't set a global language on a multilingual site.
- **Locale-scoped suggestions.** Suggestions only match entries in the same
  locale — never a cross-language link.
- **Per-rule locale scope.** [Auto-Link rules](/usage/auto-linking) can be
  restricted to specific sites/languages.
- **Locale filters.** The Links Report, Broken Links, Domains, and
  [Dashboard](/usage/dashboard) all carry a language filter so every count and
  table can be scoped to one locale.

## Requirements

Multilingual support relies on **Statamic's multisite**, which is a
[Statamic Pro](/getting-started/editions) feature. Single-site installs don't
need Pro and simply skip all of the above. Your Linkwise license covers **all
sites and locales** of one installation — there's no per-locale fee.

## Using it in the Control Panel

- Language detection and locale-scoping are automatic once your sites declare a
  `lang`.
- Use the locale filter at the top of each tab to narrow the view.
- If you enabled multisite (or upgraded) **after** building the index, the
  Overview may show a **"re-run Scan Content"** banner — some entries predate
  locale tagging. Run **Scan Content** once and the banner clears.

## Settings

Language detection is automatic from each site's `lang`. On single-site installs
you can set a global default with the `language` option; per-rule locale scope is
set on each [Auto-Link rule](/usage/auto-linking). See
[Configuration](/usage/configuration).

## Notes & limits

- Language detection follows your sites' `lang` values; set those correctly for
  accurate stemming.
- If suggestions look wrong right after enabling multisite, run **Scan Content** —
  the index needs one pass to stamp every entry's locale.
