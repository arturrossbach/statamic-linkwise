# Changelog

All notable changes to **Linkwise** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] — 2026-05-04

Initial public release.

### Added
- 7-tab Control Panel: Overview, Links Report, Broken Links, Domains, Auto-Linking, Target Keywords, URL Changer.
- Two-tier suggestion engine: title-phrase matching (primary) + TF-IDF keyword overlap (fallback) for inbound + outbound link suggestions.
- Auto-Linking: keyword → URL rules with case-sensitivity, collection scoping, once-per-post enforcement, auto-apply-on-save (per-rule + global).
- Broken Link Finder: HTTP HEAD/GET scan with retries, error-type classification (not_found / forbidden / ssl_error / timeout / connection_failed / missing_entry).
- Inline broken-link Replace, Ignore, Unignore, Single Unlink, Bulk Unlink with confirmation modals.
- URL Changer: bulk replace/unlink any URL across the site with smart-match or exact-match modes. Empty search shows all external + internal links.
- Domain Manager: per-domain `rel="nofollow"` / `"sponsored"` / `"ugc"` with Bard mark integration.
- Target Keywords: TF-IDF auto-extracted content keywords + manual custom keywords per entry. Custom keywords boost suggestion ranking.
- CSV export for Broken Links, Domains, Auto-Linking Rules. CSV import for Auto-Linking Rules (round-trip with export).
- Promote Inbound Suggestion → Auto-Link Rule one-click.
- Cross-tab "stale broken-link check" banner — appears on every tab when the index is newer than the last broken-link check.
- Heavy-bulk pattern: detached `php artisan` commands with live cross-tab progress banner, cancel, force-clear, recovery banner after page reload.
- Optimistic locking via `SafeEntrySaver` SHA-hash — concurrent editors can't overwrite each other.
- Help dropdown in header: documentation link, diagnostic ZIP download (privacy-safe + verbose), version info.
- Diagnostic ZIP export: counts/stats/runtime by default; opt-in verbose mode adds Linkwise + Laravel logs (filtered + full tail), state JSON snapshots, frontend error log.
- Frontend error reporter — Vue 3 errorHandler + `window.error` + `unhandledrejection` capture, with PII scrubber, dedup, recursion guard, 5MB log rotation.
- LogRotator: heavy-bulk command logs append (with separator + ISO timestamp) instead of overwriting — preserves prior runs for support.

### Pre-release hardening (2026-05-05)
Two-day audit sweep before tagging the public release surfaced a set of
structural defects that would have shipped silently. All fixed before V1.0:

- **Suggestion engine — long-titled entries**: news-style 24-word titles
  used to be mathematically locked out of matching. Two coupled fixes —
  generate 2-word title n-grams, score on `max(ratio, absolute)` —
  recover real signal without surfacing false positives on short titles.
- **Suggestion engine — unordered-stem fallback**: same long-title
  blindness in the cluster fallback. Conditional absolute floor active
  for titles with 6+ content words, ratio path stays strict below.
- **Indexer + Walker — non-Bard nested content**: text/textarea/markdown
  values nested in Replicator sets (Peak Cards heading + body, button
  labels, accordion content, etc.) were skipped by both read and write
  paths. Engine produced suggestions the dry-run filter then rejected;
  Peak users with content in Cards saw 0% coverage. Now walks every
  string-shaped leaf under quality filters (UUID/numeric/boolean/length).
- **TextExtractor — Bard custom sets**: `pull_quote`, author fields,
  captions, button labels embedded in the Bard tree returned empty.
  Now recurses into `attrs.values` with asset-handle blacklist + file-
  name pattern filter so quote text surfaces while `photo.jpg` stays out.
- **Inbound count semantics**: persistence dedup'd by source, modal showed
  per-anchor — same data, two contradictory numbers. Aligned to source+
  anchor key so cached counts match what the modal lists.
- **Settings audit**: removed two settings whose UI promised behavior the
  code never delivered (`ignored_html_tags`, `delete_on_uninstall`).
  Aligned `min_keyword_score` default drift between config and UI.
  Apply `max_suggestions` cap uniformly (title/stem/custom no longer
  bypass it). Added "Takes effect after re-scanning content" hints to
  index-time-only fields.
- **German stopwords**: added 7 common prepositions (für, durch, gegen,
  ohne, während, seit, trotz) plus the umlaut form `über` — was
  ASCII-only as `ueber` and never matched real German content.
- **UI — invisible buttons**: 7 broken icon names (`add` / `refresh` /
  `question-mark` / `alert-warning` / `check`) didn't resolve in Statamic's
  registry. Replaced with canonical names. Two empty column headers in
  the Suggestion Modal got proper labels + tooltips.
- **UX — sidebar suggestion counts**: count badges on the entry-edit
  sidebar now open the suggestion modal in place. Previously navigated
  to the Links Report table with no path to the modal.
- **UX — stale-counts banner**: data-driven divergence detection.
  When a modal fetch reveals the live count differs from the cached
  count for an entry the user just opened, an Alert prompts re-scan.
- **UX — stale-check banner**: dismissal persists across reloads per
  `index_built_at` value. Resurfaces only when a new scan changes the
  underlying staleness condition.

### Tested
- 314 PHP unit tests, 90 Playwright E2E tests including provoked-error, mutation wiring, computed-style visual asserts, CSV smoke, button audit.

### Privacy
- Default-state privacy-safe per Article 25 (Privacy by Design).
- Whitelisted config snapshot in diagnostic ZIP — no API keys, no URL patterns, no entry IDs.
- PII scrubber strips query strings + masks `/users/<name>` style paths.
- All data stored locally in `storage/linkwise/`. Zero telemetry. Zero SaaS callbacks.
