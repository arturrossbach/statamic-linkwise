# V1.2 Sprint — Smoke Queue (Night-of-2026-05-24)

**Status:** 8 Draft-PRs gepusht, linear gestackt A → B → C → D → E → F → G → H. Tests grün durchgehend (899 PHPUnit + Vitest auf jedem Branch).

User-Handoff: Smoke jeden PR in Reihenfolge, dann mergen. Da der Stack linear ist: A mergen → rebase B (oder einfach "Merge" GitHub-button für linear-stack), und so weiter. Wenn du out-of-order mergen willst, sag Bescheid und ich rebase.

## Stack-Übersicht

| # | Item | Branch | PR |
|---|---|---|---|
| A | Locale-Filter Pattern (Links + Broken) | `feat/v1.2-A-locale-filter` | #104 |
| B | Auto-Link Rules per-locale | `feat/v1.2-B-autolink-per-locale` | #105 |
| C | Overview Stats per-Locale-Breakdown | `feat/v1.2-C-overview-stats` | #106 |
| D | URL Changer Locale-Scope-Option | `feat/v1.2-D-url-changer-locale` | #107 |
| E | Modal Locale-Badges | `feat/v1.2-E-modal-badges` | #108 |
| F | Domains-Caption + Settings-Note | `feat/v1.2-F-captions` | #109 |
| G | titleLocale UX-Surfacing (Links Report) | `feat/v1.2-G-title-locale-ux` | #110 |
| H | README Multilang-Section | `feat/v1.2-H-readme-stub` | #111 |

## Verify-Pattern pro PR

1. Hard-Reload CP (Cmd-Shift-R)
2. `cd ~/Herd/prose-peak-test && php artisan linkwise:audit --path=locale-scope --path=suggestions-safety`
3. Tab-spezifischer Check (siehe pro-PR-Section unten)
4. Falls grün → Draft-PR auf "Ready for review" + Merge

## Per-PR Smoke-Steps

### A — Locale-Filter Pattern (#104)
- Multisite-Index (default+de+nl) Links Report → Filter-Dropdown oben rechts erscheint → "DE" → Tabelle zeigt nur DE-Entries. URL hat `?locale=de`. Hard-Reload erhält Filter
- Locale-Badge im Collection-Cell (z.B. "articles de")
- Wiederholen für Broken Links
- Single-Site: Filter-Dropdown darf NICHT auftauchen

### B — Auto-Link Rules per-locale (#105)
- Rule "Datenbank" → DE-Article erstellen. **"Limit to languages: de"** picken. Apply-Preview → nur DE-Entries matchen
- Default-Rule (keine Locale gewählt = all) → wie heute
- Pre-V1.2-Rules (ohne `locales`-Feld) → match-all behavior

### C — Overview Stats per-Locale (#106)
- Overview → "Entries Indexed" Karte zeigt Total + Chips "165 en · 10 de · 10 nl"
- Single-Site: keine Chips

### D — URL Changer Locale-Scope (#107)
- URL Changer → suchen + "Apply to: DE only" picken → Preview nur DE-Entries
- "All sites" (default) → wie heute

### E — Modal Locale-Badges (#108)
- Inbound-Modal für ein DE-Target → jede Source-Row hat ein "de"-Badge
- Outbound-Modal analog mit Target-Badges

### F — Domains-Caption + Settings-Note (#109)
- Domains-Tab → "Domains are sprach-agnostisch" italic-Hinweis unter Intro (NUR auf Multisite)
- Settings → oben amber Info-Banner "Settings affect all sites — per-site overrides planned for V1.3"

### G — titleLocale UX (#110)
- Links Report: ein Entry mit `localizable: false`-Title (DE-Localization eines EN-Origins) → italic amber "(inherited en)" neben dem Title
- Schwer reproduzierbar in prose-peak-test (Articles-Blueprint hat keinen non-localizable Title) — eventuell Blueprint mit `localizable: false` testen oder einfach das Vue-Pin als ausreichend ansehen

### H — README Stub (#111)
- README in GitHub readable, "Multisite + per-locale scoping (V1.2+)" Sub-Sektion korrekt, "Language Quality Tiers" Hinweis ehrlich, Links zu docs/ vorhanden
- Marketplace-Listing-Text + Screenshots + Packagist-Registration: DEINE Hand

## BLOCKED — entries für Probleme die ich nachts entdeckt habe

_None._ Alle 8 Items durchgekommen, alle Tests grün, kein Design-Ambiguity-Stop nötig.

## Stand der Tests am Ende (auf H)

- PHPUnit Unit + Feature: 899 grün
- Vitest: 186 grün (+5 neue LocaleFilter pins)
- Audit gegen prose-peak-test: nicht final nachgelaufen weil iterative Stack-Builds — kann beim Smoke der einzelnen PRs lokal verifiziert werden

## Was NICHT in dieser Sprint-Nacht passiert ist

- A.5 (Activity Log Locale-Filter — read-time-resolution / Schema-Bump-Decision) — separater Sub-PR nach Stack
- Marketplace Screenshots + Listing-Text — User-Hand
- Packagist-Registration — User-Account nötig
- V1.3-Tracks (Modal-Persist after Bulk, Per-Site Collections, Origin-Group-Awareness, etc.) — bleiben Memory-getrackt

## Wenn du was umstellen willst

Sag welche PR-Nummern in welcher Reihenfolge gemerget werden sollen — ich rebase + force-push die Folge-Branches. Wenn alles linear gemerget wird, ist nichts zu tun.
