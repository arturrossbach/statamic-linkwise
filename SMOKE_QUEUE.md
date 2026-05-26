# V1.2 Sprint — Smoke Queue (Night-of-2026-05-24)

User-Handoff: Liste der Draft-PRs in Merge-Order, plus konkreter Verify-Steps pro PR.

**Wichtig:** PRs sind als Linear-Stack gebaut — jeder Branch baut auf dem vorherigen auf. Wenn du in Reihenfolge mergest (A → B → C → …), brauche ich nicht zu rebasen. Wenn du out-of-order mergen willst, sag Bescheid; ich rebase die Folge-Branches.

## Verify-Pattern pro PR

1. Hard-Reload CP (Cmd-Shift-R)
2. Linkwise-Audit: `cd ~/Herd/prose-peak-test && php artisan linkwise:audit --path=locale-scope --path=suggestions-safety`
3. Tab-spezifischer Check (siehe pro-PR-Section unten)
4. Falls grün → Draft-PR auf "Ready for review" + Merge

## Stack

### A — Locale-Filter Pattern (shared)
- **Branch:** `feat/v1.2-A-locale-filter`
- **PR:** _wird unten verlinkt sobald gepusht_
- **Scope (gestrafft):** Shared `<LocaleFilter>` Vue-Component + `?locale=` Query-Param in **Links Report + Broken Links** (Tab-level). Activity Log braucht read-time-locale-Resolution (eigene PR A.5). AutoLink-Rule-Preview wird in Item B mitgenommen weil rule-level locale-scope das ohnehin anfasst.
- **Smoke:**
  - Auf Multisite: Links Report → Filter-Dropdown oben rechts → "DE" wählen → Tabelle zeigt nur DE-Entries. URL hat `?locale=de`. Hard-Reload erhält Filter
  - Wiederholen für Broken Links
  - Single-Site / single-locale-index: Filter darf NICHT auftauchen
  - **Locale-Spalte** in Links-Report-Tabelle muss `de`/`en`/`nl` (lowercase) Badge zeigen neben dem Collection-Namen

### A.5 — Activity Log Locale-Filter (separater Follow-up, PR folgt nach A)
- **Scope:** Activity-Log Filter via read-time-locale-Resolution durch Entry::find (oder Snapshot-Schema-Bump). Decision-Point — vermutlich read-time mit Caching.
- **Smoke:** noch nicht definiert.

### B — Auto-Link Rules per-locale
- **Branch:** `feat/v1.2-B-autolink-per-locale`
- **Stacked on:** A
- **Was:** Rule-Datenshape `locales: ['de']` (leer = all sites, back-compat). Rule-Edit-Form multi-select. Apply filtert nach source-entry-locale.
- **Smoke:**
  - Neue Rule erstellen: Anchor "Datenbank" → Target DE-Article. Locale = ["de"]. Speichern
  - Apply-Preview: NUR DE-Entries matchen, kein EN/NL
  - Default-Rule (keine Locale gewählt = all) verhält sich wie heute (=alle Sites)
  - Existing Rules ohne `locales`-Feld bleiben funktional (back-compat)

### C — Overview Stats per-Locale-Breakdown
- **Branch:** `feat/v1.2-C-overview-stats`
- **Stacked on:** B
- **Was:** Stats-Karten zeigen Total + "165 EN · 10 DE · 10 NL" Chips drunter. Most-Linked pro Locale separate Karte.
- **Smoke:**
  - Overview → Entries-Karte zeigt Total + 3 Locale-Chips
  - Most-Linked-Karten: 3 Locale-Varianten oder Locale-Tabs
  - Single-Site: keine Chips (nur Total)

### D — URL Changer Locale-Scope
- **Branch:** `feat/v1.2-D-url-changer-locale`
- **Stacked on:** C
- **Was:** Form-Option "Apply to" mit Site-Auswahl. Preview filtert entsprechend.
- **Smoke:**
  - URL Changer → Find-Replace ausfüllen + Site auf "DE" → Preview zeigt nur DE-Entries
  - "All sites" gleich wie heute
  - Apply-Action respektiert den Scope

### E — Modal Locale-Badges (Inbound + Outbound)
- **Branch:** `feat/v1.2-E-modal-badges`
- **Stacked on:** D
- **Was:** Locale-Code-Badge im Modal-Header + per-Row in Source/Target-Liste.
- **Smoke:**
  - Inbound-Modal für ein DE-Target öffnen → Header zeigt "(de)" Badge, jede Source-Row hat ihren Locale-Code (nach Same-Locale-Filter alle "de")
  - Spiegel für Outbound

### F — Domains-Caption + Settings-Note
- **Branch:** `feat/v1.2-F-captions`
- **Stacked on:** E
- **Was:** kurze Hinweis-Texte
- **Smoke:**
  - Domains: Hinweis "Domains are sprach-agnostisch" oben
  - Settings: "These settings affect all sites — per-site overrides planned for V1.3" als Info-Banner oben

### G — titleLocale UX-Surfacing
- **Branch:** `feat/v1.2-G-title-locale-ux`
- **Stacked on:** F
- **Was:** Wenn ein Entry titleLocale ≠ locale (= non-localizable Title, inherited from Origin) → Badge/Tooltip im UI.
- **Smoke:**
  - Erstmal nicht trivial reproduzierbar in prose-peak-test (Article-Blueprint hat keinen non-localizable Title). Eventuell brauchst du dafür einen Blueprint-Edit der Title `localizable: false` setzt. ALTERNATIV: Pin-Test in der Vue-Layer reicht für Visual-Verifikation
  - Falls Reproduktion: DE-Localization mit EN-Origin-Title öffnen → Tooltip "Title inherited from EN origin"

### H — README Stub
- **Branch:** `feat/v1.2-H-readme-stub`
- **Stacked on:** G
- **Was:** README-Sektion "Language Quality Tiers" + "Multilang Setup" + Stub-Marker für Marketplace-Listing-Text + Screenshots (deine Hand).
- **Smoke:**
  - README in Browser readable, Tier-Listung korrekt, Marketplace-Section markiert `<!-- TODO -->`
  - Du füllst Marketplace-Text + Screenshots selbst

## BLOCKED — entry für Probleme die ich nachts entdecke

(Hier landen Design-Ambiguities die ich nicht alleine entscheiden will. Wenn dieser Block beim Aufwachen leer ist = alles glatt.)

_None yet._
