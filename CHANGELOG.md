# Changelog

All notable changes to **Linkwise** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

_No unreleased changes._

## [1.2.3] — 2026-05-29

Reliability release from a full-project code review. No new features — data-safety
and correctness fixes plus two control-panel display fixes.

### Fixed

- **Data safety:** A malformed (invalid-UTF-8) byte in entry content can no
  longer truncate the link index, indexer state, or auto-link rules to an empty
  file. JSON writes now verify the payload encoded and reached disk in full
  before replacing the existing file; on failure the previous file is kept.
- **Indexing:** Saving a draft — or unpublishing an entry — no longer adds it to,
  or leaves it in, the link index when the index is set to published entries only.
  The incremental save path now matches a full Scan Content.
- **Broken-link checker:** Far fewer false positives. Servers that reject `HEAD`
  but serve `GET` fine (common behind WAFs/CDNs) are now confirmed with a `GET`
  before being flagged, and a momentary timeout or connection error is no longer
  cached as "broken" for 24 hours — the next scan re-checks it.
- **Auto-link rules:** Editing a rule's keyword to one that already exists is now
  rejected with a clear message, matching the check that already applied when
  creating a rule.
- **Links Report:** The completion toast for inbound/outbound link insertion now
  shows the entry name instead of being blank.

## [1.2.2] — 2026-05-27

Polish and reliability release. No new features; all changes are fixes,
UI improvements, and internal hardening.

### Fixed

- **Links Report:** Removed speculative "+3" inbound/outbound link-count
  badges. The "3 links = good" threshold had no primary source and was
  removed to avoid misleading SEO guidance. The `orphan` badge (0 inbound)
  is retained — that threshold is Google-documented.
- **AtomicJsonWriter:** Byte-exact write verification closes a silent-
  truncation window (CR-H-3). Previously `!== false` did not catch partial
  writes on near-full disks; the check now verifies `$written === $expectedBytes`
  and logs an error on mismatch.

### Improved

- **Suggestion Modal:** New collapsible "What Google says about internal
  links" guide. Every claim is a verbatim quote from Google Search Central
  documentation — no invented thresholds or ranking promises. The guide is
  mode-aware: inbound context shows the "every page you care about should
  have a link" quote; outbound context shows the anchor-text guidance.
- **Suggestion Modal:** Pre-flight observations panel surfaces anchor-
  concentration warnings and generic-anchor signals before insertion, based
  on measured facts from the current index.
- **Broken Links:** Color-coded status badges per error type (red for 404 /
  missing entry, yellow for timeout / SSL / redirect, orange for 403 /
  server error). Fixed-pill indicator appears on resolved rows; edited URLs
  show the original as struck-through.
- **Layout:** Installed Linkwise version now shown in the Help dropdown,
  sourced from the Statamic Addon registry. Null-safe — hides entirely on
  dev path-repo installs where no version tag exists.
- **Layout:** Onboarding modal now includes a step-by-step "What's next?"
  guide and direct links to docs, FAQ, changelog, and support.

### Refactored (internal — no behavior change)

- `StaleCheckPresenter` extracted from `InertiaPagesController`. The stale-
  check and exec-availability props are now built in one place and spread
  into all 8 page renderers from there.
- `SafeEntrySaver::load()` null-check contract formally documented in
  phpdoc; callers' expected guard pattern pinned.

### Tests

- `AtomicJsonWriterTest` — pins byte-exact write, truncation detection,
  and atomic-swap guarantee.
- `SafeEntrySaverLoadContractTest` — pins null-entry contract for deleted /
  never-existed entries.
- `PhaseRegistryParityTest` — architecture parity: every registered phase
  has a matching handler.
- `InertiaRendererRequiredUrlPropsTest` — extended to cover all 8 page
  renderers with 74 assertions.

## [1.2.1] — 2026-05-26

Marketplace submission release. No behavior changes.

### Fixed

- **Links Report:** Hide per-row locale badge on single-locale installs.

### Docs / Listing

- README rewritten: dropped unverified claims, aligned with Statamic
  Marketplace submission requirements.
- FAQ: English-only examples, accurate language-support list, removed
  three pre-launch lies caught in audit.
- Marketplace listing copy finalized.
- LICENSE: minimized to supplemental terms, aligned with Statamic
  Marketplace ToS.

## [1.2.0] — 2026-05-26

The Multilingual-content release. Linkwise now scopes Suggestions, Auto-Link
Rules, URL-Changes, and most UI surfaces to the entry's site `lang:`
declaration. Single-site or single-language installs see no behavior change — every multilingual
feature hides itself when the index carries fewer than two distinct locales.

_Note: "multisite" and "multilingual" are not synonyms. Multisite = multiple Sites configured in `sites.yaml`. Multilingual = content in ≥2 languages. The V1.2 features below activate on multilingual content, not on multi-domain-same-language setups._

### What's new

- **Same-locale Suggestion filter.** A DE-source entry's Inbound and
  Outbound modals only surface DE-target candidates. EN sources don't
  see DE targets and vice versa. Per-source-locale stemmer + stopwords
  close the cross-language mismatch from the PR #100 saga at the
  source. Single-site / legacy-record entries (locale=null) pass through
  unchanged.
- **Per-Auto-Link-Rule locale scope.** New "Limit to languages"
  multi-select in the Rule editor. Empty = match all sites (back-compat
  for pre-1.2 rules). Filter fires on the apply path AND on the
  on-entry-save subscriber so a DE-only rule never silently writes
  links into EN entries when the editor saves.
- **Per-locale URL Changer.** Search-form "Apply to" selector restricts
  domain migrations to a single site. Plus: bare-UUID search now finds
  internal links without requiring the `statamic://entry::` prefix, and
  the Current-URL column resolves internal hrefs to entry titles
  instead of opaque UUIDs.
- **Locale-Filter widget** on Links Report and Broken Links. Native
  `<select>` matching the existing Collection-filter visual pattern.
  URL-state persisted so refresh + browser-back work.
- **Locale badges** in Suggestion modals (Inbound + Outbound) and in
  the Links Report Collection cell. Lowercase ISO code, hidden when
  the entry has no locale stamp.
- **Per-locale entry-count chips** on the Overview's "Entries Indexed"
  card. "185 entries / 165 en · 10 de · 10 nl".
- **Inherited-title hint.** When a blueprint declares `title: { localizable: false }`
  and an entry is a localization of an origin in another language,
  the Links Report shows an italic "(inherited en)" hint so editors
  know the title is foreign-language.
- **Multilingual re-index banner** on Overview. Surfaces when persisted
  records lack locale stamps (pre-1.2 indices). One Scan Content
  upgrades them.
- **`linkwise:seed-multilingual`** command. Creates origin-linked
  EN/DE/NL translation triplets for local smoke testing.
- **FAQ doc** ([`docs/FAQ.md`](docs/FAQ.md)). 9-section reference
  covering Getting Started, Multilang, Auto-Linking, Suggestions,
  Performance, Hosting, Troubleshooting, Licensing, and Data/Privacy.
  Linked from the onboarding welcome screen and the README header.

### What's changed

- **`linkwise.language` Settings field renamed** to "Content language
  (fallback)" with instructions explaining its role per install-type.
  On modern multisite installs (sites with `lang:` declared), this
  field is essentially inactive — Linkwise reads each entry's site
  lang directly.
- **Coordinator-stopword list extended** to all 14 CONFIDENT-tier
  languages (FR/ES/IT/NL/PT/SV/DA/NO/FI/RO/RU/CA in addition to
  EN/DE). Grammar-reference-derived, not native-speaker validated —
  report edge cases via GitHub Issues for a one-line patch.
- **Activity Log entry resolution** is forensically richer.
  `BulkSnapshotStore::appendWrittenItem` captures source + target
  titles at write time so the drawer can render readable labels
  ("(deleted entry)" fallback) instead of raw UUIDs when entries are
  removed between bulk-completion and viewing.
- **`localizable: false` on title/body fields** is now respected by the
  Indexer. Pre-1.2 the indexer would stem inherited foreign-language
  text with this entry's locale; now it stamps `titleLocale` separately
  so the SuggestionEngine stems with the right language.

### What's fixed

- **Cross-locale leak in Inbound modal.** The Inbound code path built
  a single-target `$index` for the engine, which made `$index[$excludeEntryId]`
  null and bypassed the same-locale filter. Source locale now passes
  explicitly via `sourceLocaleOverride`.
- **`generateMatchPhrases` + `trimBoundaryStopwords`** were globally
  scoped, so a DE-title in an EN-default install left "die" / "der"
  dangling on anchor boundaries. Both now take an explicit locale.
- **`$site->lang()` instead of `$site->shortLocale()`** as the source
  for entry-locale stamping. Catches the Swiss-DE pattern (`locale:
  en_US` for date format + `lang: de` for content).

### Notes

- **Coordinator-list community-validation** (FR/ES/IT/NL/PT/SV/DA/NO/FI/RO/RU/CA)
  is hand-curated from grammar references; please open an issue if
  you spot a false-reject in your language. Documented as
  `feedback_known_fragility_coordinators` for tracking.
- **Audit doc**: full code-level rationale lives in
  [`docs/MULTISITE_AUDIT.md`](docs/MULTISITE_AUDIT.md). UX gap
  inventory + V1.3 roadmap in [`docs/POST_MULTILANG_GAPS.md`](docs/POST_MULTILANG_GAPS.md).
- **V1.3 deferred items** (per audit triage): Modal-Persist after
  Bulk, Origin-Group-Inheritance for Custom Keywords + Excluded
  Entries, per-Site `linkwise.collections` override, per-locale
  Stopwords, Activity-Log locale-filter (needs snapshot-schema-bump),
  Auto-Link Rules locale-aware Stemming (F2).

## [1.1.0] — 2026-05-22

First post-launch release. Bundles the Cloudways production-smoke fixes,
several UX cleanups, and a few additive features. No breaking changes
to settings or stored data.

### What's new

- **Per-pair ignored-suggestions blocklist.** Editors can mark a
  (source, target) pair as "don't suggest this again" — the ignore
  survives re-scans. Pair is undirected, sorted internally so the same
  conceptual pair always serialises identically. ([#74])
- **Frequency-based keyword filter.** Top-50000 global word-frequency
  list (Snowball-stemmed, title-protected) filters filler words from
  TF-IDF keyword extraction so suggestions surface meaningful terms,
  not "still / very / really". ([#75], [#76])
- **Exec-availability pre-flight banner.** Linkwise now warns up-front
  when PHP `exec()` / `proc_open()` are disabled via `disable_functions`.
  Visible constraint instead of silently-broken Scan Content. ([#80])
- **Welcome-screen onboarding checklist.** First-run users see a 4-step
  checklist (pick language → choose collections → first Scan → browse
  Links Report). ([#84])
- **Seed test data command.** `php artisan linkwise:seed-test-data 30
  --with-home` populates a fresh site with realistic article + page
  content for local smoke tests. Cycle support for large counts. ([#88],
  [#91], [#92])

### What's changed

- **"Target Keywords" tab renamed to "Custom Keywords".** The "Target"
  wording had a deprecated SEO reading; the new label matches what the
  tab actually manages. The auto-extracted content-keywords column is
  removed from the table (the extraction path was deactivated in favor
  of title-matching); the same list still appears in the Add Keywords
  modal as a read-only reference, copyable into custom keywords. ([#89])
- **Excluded entries no longer leak into reports.** The
  `excluded_entries` setting now only filters the Suggestion machinery
  — Domains, Broken Links and URL Changer show every entry regardless.
  Matches the blueprint copy "neither suggested nor suggesting". ([#87])
- **Overview recommendations are dismissable.** Per-recommendation ✕
  button persists in sessionStorage; banners reappear in a fresh
  browser session if the underlying condition still holds. ([#90])
- **Stale-broken-link banner removed entirely.** Was nagging editors
  for index updates they planned to recheck on their own cadence. ([#90])
- **Suggestion-overlap toggle marked experimental + off by default.**
  Removed the orphaned `min_keyword_score` field from the settings UI.
  ([#77], [#78], [#79])
- **BARD developer badge removed** from the Bard editor toolbar. ([#81])

### What's fixed

- **Select-All in the Suggestion modal now respects ignored pairs.**
  Pre-fix, master-checkbox added ignored items to the bulk; the server
  inserted them anyway. Three-layer defense — modal counter, emit
  filter, server-side per-item gate in `LinkInsertCommand`. ([#85])
- **URL Changer Apply works for multi-link Markdown fields.** Pre-fix,
  the second-and-later matching link in a Markdown field skipped with
  "Links were already gone — Run Scan Content". Counter semantic
  aligned with Bard/Replicator (global hrefMatches counter, not URL-
  restricted regex). API parity: `replaceNthInMarkdown` now takes
  `$search` as 2nd positional argument, matching its sister methods.
  ([#86])
- **Excluded entries are no longer silently hidden from Domains and
  Broken Links.** See "What's changed" → "Excluded entries no longer
  leak". ([#87])

### Internal

- **Bundle now shipped in the repo** — no more `npm run build` step
  required after `composer require`. ([#83])
- **Package renamed to `arturrossbach/statamic-linkwise`** for
  Marketplace consistency. ([#82])
- **Settings UI: range-validation + auto-detected language display.**
  ([#71], [#72], [#73])
- **Frontend bundle** ships with hashed filenames; Statamic vendor-
  publish picks the active hash from the manifest.

### Action required after upgrade

Run `php artisan linkwise:index` once to pick up the per-pair ignored
store + the frequency-filter pass. Hard-refresh the Control Panel.

[#71]: https://github.com/arturrossbach/statamic-linkwise/pull/71
[#72]: https://github.com/arturrossbach/statamic-linkwise/pull/72
[#73]: https://github.com/arturrossbach/statamic-linkwise/pull/73
[#74]: https://github.com/arturrossbach/statamic-linkwise/pull/74
[#75]: https://github.com/arturrossbach/statamic-linkwise/pull/75
[#76]: https://github.com/arturrossbach/statamic-linkwise/pull/76
[#77]: https://github.com/arturrossbach/statamic-linkwise/pull/77
[#78]: https://github.com/arturrossbach/statamic-linkwise/pull/78
[#79]: https://github.com/arturrossbach/statamic-linkwise/pull/79
[#80]: https://github.com/arturrossbach/statamic-linkwise/pull/80
[#81]: https://github.com/arturrossbach/statamic-linkwise/pull/81
[#82]: https://github.com/arturrossbach/statamic-linkwise/pull/82
[#83]: https://github.com/arturrossbach/statamic-linkwise/pull/83
[#84]: https://github.com/arturrossbach/statamic-linkwise/pull/84
[#85]: https://github.com/arturrossbach/statamic-linkwise/pull/85
[#86]: https://github.com/arturrossbach/statamic-linkwise/pull/86
[#87]: https://github.com/arturrossbach/statamic-linkwise/pull/87
[#88]: https://github.com/arturrossbach/statamic-linkwise/pull/88
[#89]: https://github.com/arturrossbach/statamic-linkwise/pull/89
[#90]: https://github.com/arturrossbach/statamic-linkwise/pull/90
[#91]: https://github.com/arturrossbach/statamic-linkwise/pull/91
[#92]: https://github.com/arturrossbach/statamic-linkwise/pull/92

## [1.0.0] — 2026-05-21

Initial public release.

### Sister-Bug Audit Wave (post-internal-milestone hardening, 2026-05-16 → 2026-05-21)

Twelve audit/fix PRs (#58–#69) closing structurally documented bug
classes via PHPUnit + Vitest source-grep pins. Klassen 4.x (filter-
apply argument parity), 7 (async-bulk recordBulkSkipped + post-
completion reload), 8 (per-page required-prop completeness), 9 (per-
kind terminal-status shape parity), 10 (deep-clone-prop stale after
Inertia reload) — all closed with structural pins preventing
re-manifestation. Full surface in
[`architectural_health.md`](docs/) and
[`docs/SISTER_AUDIT_2026_05_17.md`](docs/SISTER_AUDIT_2026_05_17.md).

### Fixed (pre-release-hardening Welle 2026-05-16 → 2026-05-21)
- **Indexer-Writer field symmetry**: plain `text` / `textarea` fields (top-level) and plain-string values nested inside Replicator sets are no longer indexed as anchor sources. They were never reachable by the link-insertion path; indexing them produced phantom inbound/outbound suggestions that failed at apply-time with "anchor text not found". The Indexer now reads only what `BardLinkInserter` can write: Bard, Replicator-nested Bard, and top-level Markdown.
- **Inbound dry-run filter parity**: `InboundEngine::suggestFiltered` now passes `sentence_context` to the dry-run inserter, matching the real-write path in `LinkInsertCommand`.
- **Reindex cache coherence**: `linkwise:index` now flushes the `InboundSuggestionCache` for all indexed entries after the fresh index is saved.
- **Activity-Log skip-records for ApplyRule**: 5th bulk-Command now writes `recordBulkSkipped` for hash-conflict skips, parity with the 4 sister commands.
- **ApplyRule toast surfaces skipped entries**: `completionLabel('applyrule')` includes the new `conflicts_skipped` count ("3 link(s) added, 1 skipped — entry was modified by another editor").
- **Auto-Link tab refresh after Apply/Unlink/Multi-rule**: all 3 paths now use the canonical `:key="renderKey"` parent-remount pattern. Solves Klasse 10 (deep-clone-prop stale after Inertia partial-reload).
- **Universal post-bulk refresh across 6 pages**: Links/Broken/Domains/Keywords/UrlChanger/Overview pages now bump `renderKey` + Inertia partial-reload on every `bulkState.lastCompletion` terminal → child tab re-mounts with fresh props. No more per-counter "which watcher did I forget" debugging.
- **Context-extraction window** bumped from 120/160 to 240 chars default. Display-only callers (DomainReport, BrokenLinkChecker, link-context badges) now relax the paragraph-clamp via `clampToParagraph: false` so very short paragraphs (caption-style) get surrounding sentences instead of just the anchor.

### Added (UX polish, 2026-05-21)
- **Target Keywords — exclude generated keywords**: per-entry block-list via ✕ on auto-extracted content-keyword badges. Two-step: mark as pending → Save (with confirmation modal) → committed; survives `Scan Content`. Cancel + Undo per badge.
- **Domains modal** widened to `size="full"` for the longer sentence-context column.
- **URL Changer + Domains modal**: Anchor column removed (redundant — anchor is highlighted inline in the Context column).

### Action required after upgrade
Run `php artisan linkwise:index` once. Hard-refresh the Control Panel.

## [1.0.0-pre] — 2026-05-04 (internal milestone, never tagged)

Original V1.0 feature-complete milestone. Tagged retroactively here
for historical record; the actual public release is 1.0.0 above.

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
