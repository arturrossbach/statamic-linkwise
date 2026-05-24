# Post-Multilang Feature-Lücken (Tab-by-Tab Brainstorm)

Status: DRAFT — User-Anfrage 2026-05-24 nach PR #101-Merge. Pro Tab sechs Prompts (siehe Framework unten). Listet, ranked nicht. Companion-Dokument zu `docs/MULTISITE_AUDIT.md`.

**Framework pro Tab:**
1. Welche Daten zeigt der Tab — locale-scoped oder aggregiert?
2. Was erwartet ein Editor auf einer bestimmten Site?
3. Welche Mutations gibt's — und auf welchem locale-Scope?
4. Welche Settings/Configs treiben den Tab — brauchen sie per-locale-Varianten?
5. Was rippled in ANDERE Tabs wenn hier was passiert?
6. Multilang-Feature-Lücke — was würde ein DE+EN+NL-Customer fragen?

---

## Overview

**Data shown:** Site-aggregierte Health-Stats (total entries, total internal/external links, broken count, most/least linked Entries, domain count). Plus `resolvedLanguage`-Panel + Multisite-Reindex-Banner (C1).

**Editor's expectation on a site:** Im CP-Site-Switcher auf "DE" gewechselt → erwarte ich DE-spezifische Stats? Oder bleibt's globale Aggregation? Wahrscheinlich Mischform: globale Counts sind nützlich für "wie groß ist der Index insgesamt", aber per-locale-Counts würden zeigen "wie gut linked ist mein DE-Content gegenüber EN".

**Mutations + locale-scope today:**
- "Scan Content" trigger — index-weit, alle Sites
- "Reindex needed" banner click — selber trigger
- Keine per-locale Mutations heute. Wenn aber Cross-Tab-D (per-Site-Collections-Override) landet, wird "Scan Content" implizit eine per-Site-Frage: scan triggert für welche Site, mit welchen Collections? Trigger-UX braucht dann ein "Scan: All sites / DE only / EN only"-Submenu.

**Config dependencies:**
- `linkwise.collections` (welche Collections indexiert werden) — global, kein Site-Filter
- `linkwise.target_collections` — global
- `linkwise.entry_status` — global
- `linkwise.language` — globaler Fallback (per M1 relabel)

**Cross-tab ripples:** Wenn Overview-Counts auf locale-Filter umgestellt werden, müssen Most/Least-Linked auch locale-gefiltert sein, sonst inkonsistent zu Links Report.

**Feature gaps:**
- Per-Site Counter-Karten: "DE Entries: 10, DE Internal Links: 24, DE Broken: 0" als sekundäre Reihe unter den globalen Zahlen
- Most-Linked-Entry-Karte: zeigt heute beliebigen Entry — sollte pro Site/Locale top sein (oder Karte zeigen "DE most linked / EN most linked / NL most linked")
- "Resolved language"-Panel ist auf Multisite-Installs irreführend: zeigt nur globalen Fallback, sollte stattdessen eine Site-Liste anzeigen ("default→en, de→de, nl→nl") plus den Fallback als Footnote
- Multisite-Reindex-Banner: aktuell binär (null-locale-Records → Banner). Sollte zeigen WIE VIELE Records noch null sind und WELCHE Sites betroffen sind

**Maps to audit:** M1 (settings label), C1 (banner), B5 (resolved language CP-context).

---

## Links Report

**Data shown:** Tabelle aller Entries mit inbound/outbound count, content hash, has-title-match-flag, suggestion counts. Plus per-Zeile "View Suggestions" Modal-Trigger.

**Editor's expectation on a site:** Auf DE-Site → erwarte ich DE-Entries OR alle Entries mit Locale-Spalte. Mixed-locale-Liste ohne Filter ist verwirrend wenn der Editor mit DE-Content arbeiten will.

**Mutations + locale-scope today:**
- Bulk-Unlink (outbound) per Entry — operiert auf dem Entry direkt, locale-orthogonal
- Bulk-Insert via Suggestion-Modal (inbound/outbound) — heute korrekt locale-scoped (PR #101 + Inbound-Fix)
- Detail-Unlink async — per Link
- Relink — per Link

**Config dependencies:** Same as Overview (`collections`, `target_collections`, `entry_status`).

**Cross-tab ripples:** Locale-Filter hier müsste auf Suggestion-Modal-Content durchschlagen — aber das ist schon korrekt locale-scoped, also nur Tabellen-Filter relevant.

**Feature gaps:**
- **Locale-Spalte fehlt** — Editor kann nicht erkennen welcher Entry zu welcher Site gehört. Locale-Code (de/en/nl) oder Flag-Icon als sekundäre Info
- **Locale-Select-Filter** (User-Anfrage 2026-05-24) — Dropdown oben rechts "All languages / EN / DE / NL". Query-Param-Persistent
- **has_title_match-Flag pro Locale** — aktuell global "hat irgendwo einen Title-Match-Suggestion". Auf Multisite sinnvoller: "DE-Title-Match" / "EN-Title-Match" separate Spalten
- **Sort by locale** — wenn Filter gesetzt, sollte Sort innerhalb der gefilterten Menge funktionieren
- **Origin-Group-View**: Editor klickt auf einen Article → sieht alle Localizations untereinander gruppiert. Aktuell wären's 3 separate Zeilen ohne Verbindungsanzeige
- **Per-locale Orphan-Detection**: ein EN-Entry der nur von DE-Entries verlinkt wird ist nach unserem locale-Filter "orphan from EN-side". Aktuelle Orphan-Logik berücksichtigt das nicht

**Maps to audit:** Locale-Filter-Track (Sub-PR), B2/B3 (origin chain).

---

## Broken Links

**Data shown:** Tabelle externer Links pro Entry mit Status (checked/broken/redirect/timeout). Plus Bulk-Check-Aktion.

**Editor's expectation on a site:** Genau wie Links Report — DE-Editor will DE-Broken-Links sehen. Plus: brokenness ist site-orthogonal (eine kaputte external URL ist überall kaputt), aber EDITORIAL-Verantwortlichkeit ist site-gebunden (DE-Editor fixt DE-Entry-Broken-Links).

**Mutations + locale-scope today:**
- Bulk-Check (HEAD-Request scan) — alle Entries, alle Sites
- Replace-URL / Mark-Fixed — per Link

**Config dependencies:** `linkwise.broken_link.user_agent`, `timeout`, etc. — global, sinnvoll als global.

**Cross-tab ripples:** Broken Link Replace führt zu Activity-Log-Eintrag mit source-entry-locale. URL Changer kann broken→fixed-URL-Migration triggern.

**Feature gaps:**
- **Locale-Select-Filter** — Editor will nur seine eigenen DE-Entries sehen
- **Locale-Spalte** — gleich wie Links Report
- **Site-aware Email-Notifications** (Future): wenn ein DE-Editor existiert separat vom EN-Editor (Statamic-Permission-Layer), Broken-Link-Mails nur an verantwortlichen Editor
- **Cross-locale duplicate URL detection**: drei Localizations linken auf dieselbe broken URL → eine Replace-Action sollte alle drei fixen können (Bulk-Multi-Locale)

**Maps to audit:** K2 (Broken Links multisite-orthogonal — bestätigt, kein code-Fix nötig, aber UX-Filter sinnvoll).

---

## Domains

**Data shown:** Aggregierte Liste aller externen Domains aus allen Outbound-Links. Per-Domain Count + custom attributes (notes, nofollow-rule, etc.).

**Editor's expectation on a site:** Domains sind sprach-orthogonal (https://github.com bleibt github.com). Editor erwartet NICHT pro-Site-Domain-Liste. Aber: "welche meiner DE-Entries linken auf github.com" wäre nützlich.

**Mutations + locale-scope today:**
- Edit Domain Attributes (notes, nofollow flag) — global pro Domain

**Config dependencies:** None tab-spezifisch.

**Cross-tab ripples:** Domain-Attributes flow zu Bard-Render (nofollow-attribute). URL Changer nutzt Domains als auto-complete.

**Feature gaps:**
- **Per-Domain Locale-Breakdown**: Domain-Detail-View "Used by: 12 entries (5 EN, 4 DE, 3 NL)" — gibt Editor Kontext für relevance
- **Locale-Filter im Reverse-Lookup**: "Show entries linking to github.com" → mit Locale-Filter
- **Site-specific Domain-Attributes**: nofollow-Pattern pro Site (DE-Markt hat andere Compliance-Anforderungen als EN-Markt). Niche, V2.

**Maps to audit:** K3 (Domains multisite-orthogonal — confirmed).

---

## Auto Linking

**Data shown:** Liste aller Auto-Link-Rules + Preview-Counts pro Rule (match_count, linked_count, linked_elsewhere_count, not_insertable_count).

**Editor's expectation on a site:** Auf DE-Site → erwarte ich DE-relevante Rules sichtbar/aktiv. EN-Rules sollten nicht auf DE-Content feuern. Aktuell brennen alle Rules über alle Sites.

**Mutations + locale-scope today:**
- Create/Update/Delete Rule — Rule ist global, hat keinen locale-Tag
- Apply Rule (preview + actual write) — über Index, alle Locales
- Bulk-Toggle, Bulk-Delete — auf Rule-Liste

**Config dependencies:**
- `linkwise.auto_apply_on_save_enabled` — global; bei Multisite würde sinnvoll sein per-site override
- Rule-Liste selber lebt in storage/linkwise/autolink-rules.json

**Cross-tab ripples:** **Heaviest cross-tab dependency im ganzen Linkwise.** Rule writes → Activity Log entries (per Apply), Links Report counts shift, Broken Link wahrscheinlich nicht direkt aber kann broken target erzeugen wenn target gelöscht wird.

**Feature gaps:**
- **Per-Locale Rule-Scope** (audit F1): Rule-Datenshape erweitern um optional `locales: [de, nl]`. Default = all = aktuelles Verhalten. Editor erstellt "Datenbank → /datenbank" als DE-Rule, "Database → /database" als EN-Rule. Rules feuern nur auf passende Source-Locale
- **Auto-Detection at Rule-Save**: Rule-Anker enthält Umlaut/typisch deutsches Wort → vorschlagen "Ist das eine DE-Rule? [Yes / No / Apply to all sites]"
- **Preview-Filter nach Locale**: Rule-Detail-View → Match-Preview-Tabelle filterbar nach Locale (analog Links Report)
- **Per-Locale Rule-Conflict-Detection**: Rule "Database → /db-en/" UND "Database → /db-de/" wären cross-locale Konflikt. Heute keinerlei Erkennung; mit Locale-Scope auch nicht nötig, aber bei All-Sites-Rules nützlich
- **Rule-Anchor Stemming-Sprache**: heute global Stemmer. Mit Locale-Scope sollte pro Rule der passende Stemmer laufen (audit F2)
- **Rule-Import/Export per Locale**: User exportiert nur DE-Rules → können sauber in andere DE-Site importiert werden

**Maps to audit:** F1, F2, F3.

---

## Custom Keywords

**Data shown:** Per-Entry Custom-Target-Keywords (Editor markiert "wenn jemand 'X' schreibt, link auf diesen Entry"). Plus excluded-content-keywords (negative liste).

**Editor's expectation on a site:** Auf DE-Site → erwarte ich Keywords pro DE-Entry. Editor will NICHT versehentlich englische Keywords auf seinem DE-Entry sehen.

**Mutations + locale-scope today:**
- Add/Remove Keyword per Entry — speichert per Entry-UUID. Da Localizations separate UUIDs haben, sind Keyword-Sets automatisch getrennt.
- Excluded-Content-Keywords — globale Liste, alle Sites

**Config dependencies:** None tab-spezifisch.

**Cross-tab ripples:** Custom Keywords → Suggestion-Engine (per InboundEngine::findCustomKeywordMatches + entsprechende Outbound-Funktion). Heute mit locale-Filter abgesichert seit Inbound-Fix.

**Feature gaps:**
- **Origin-Group-Inheritance-Hint** (audit G1): wenn Editor auf DE-Localization eines Entries Keywords setzt, fragen "Origin (EN) hat 4 Keywords — von EN-Original kopieren?" Mit manuellem Übersetzungsschritt. NICHT automatisch übernehmen
- **Locale-Filter im Tab**: Liste aller Entries mit Keywords filterbar nach Site
- **Per-Locale Excluded-Content-Keywords**: globale Liste vs per-Locale-Liste. Sehr Niche
- **Keyword-Translation-Suggestion (V2)**: LLM-basierte Übersetzung der Keywords beim Localize-Anlegen
- **Detection wenn Keyword in falscher Sprache zugewiesen**: DE-Entry kriegt englisches Keyword → Warnung "Looks like a non-DE keyword on a DE entry, was that intentional?"

**Maps to audit:** G1, D5.

---

## URL Changer

**Data shown:** Form für Find-and-Replace URL-Pattern + Preview-Matches pro Domain.

**Editor's expectation on a site:** URL-Migrations sind typischerweise Site-orthogonal (alte Domain → neue Domain überall). ABER: wenn DE-Markt eine separate Domain hat (`alte-de-domain.com` → `neue-de-domain.com`), würde Editor nur DE-Entries betroffen erwarten.

**Mutations + locale-scope today:**
- Apply-Replace (async) — über alle Entries die ein Match haben, alle Sites

**Config dependencies:**
- `linkwise.collections` — bestimmt welche Entries überhaupt in URL Changers Preview-Pool auftauchen. Wenn Cross-Tab-D (per-Site-Collections-Override) landet, ändert sich der Preview-Pool pro Site
- `linkwise.broken_link.*` — geteilter Config-Space mit Broken Links. URL Changer kann Broken-URL-Migrations triggern; ein User der broken_link Settings ändert, beeinflusst beide Tabs gleichzeitig
- `linkwise.target_collections` — strikt nicht für URL Changer relevant (target_collections scoped Suggestions, nicht URL-Scan), aber Editor könnte Verwirrung haben "warum sehe ich diese Domain hier wenn die Quell-Collection nicht in target_collections steht"

**Cross-tab ripples:** URL Changer Apply → Activity Log Eintrag (per-source-locale), Broken Link rechecks, Domains list update.

**Feature gaps:**
- **Locale-Scope für Replace**: Optional "Apply only to entries on site: [select]" — wenn DE-domain-migration nur DE-Entries betreffen soll
- **Preview-Filter nach Locale**: Match-Preview-Tabelle filterbar
- **Per-Locale Domain-Mapping-Bulk** (V2): "domain-a.com → domain-b.com" PLUS pro-locale-Mapping in einem Schwung ("domain-a.com→de-domain.com on DE, →en-domain.com on EN")

**Maps to audit:** K1 (URL Changer multisite-orthogonal — confirmed; UX-Filter aber sinnvoll).

---

## Activity Log

**Data shown:** Bulk-Snapshot-Liste mit kind, started_at, started_by, entry_count, preview_titles + per-Snapshot Detail-Drawer.

**Editor's expectation on a site:** Auf DE-Site → erwarte ich Activity die DE-Entries betraf. Heute alle Snapshots aller Sites durcheinander.

**Mutations + locale-scope today:**
- Revert (per Snapshot) — operiert auf den im Snapshot gespeicherten Entry-IDs, locale-orthogonal

**Config dependencies:** None.

**Cross-tab ripples:** Activity-Log-Listing zeigt 5 preview-titles. Falls Entries gelöscht wurden ist neuerdings "(deleted entry)" statt UUID (per heutigem Fix).

**Feature gaps:**
- **Locale-Filter** (User-Anfrage 2026-05-24): "Show snapshots that touched X locale" — filtert nach source-/target-entry-locale aus den snapshot items
- **Site-Label pro Snapshot-Eintrag**: Listing-Zeile zeigt "Bulk insert (DE/EN/NL)" anhand der involvierten Sites
- **Per-Locale Snapshot-Grouping**: optional Group-by-Locale-View
- **Origin-aware Revert**: wenn ein Bulk auf 3 Localizations eines Origins schreibt, sollte Revert-Toolbar "Revert nur DE-Localization / Revert alle 3" anbieten
- **Activity-Drawer Locale-Hinweis pro Source-Entry**: Spalte "Source entry" jetzt zeigt Title, aber nicht das Locale-Flag. Sollte "🇩🇪 Title" zeigen

**Maps to audit:** K4 (Activity-Log Site-Label).

---

## Settings

**Data shown:** Statamic Addon-Settings-Page mit Linkwise-Konfiguration (language, collections, target_collections, excluded_entries, custom_stopwords, broken_link_settings, etc.).

**Editor's expectation on a site:** Settings sind global, kein Site-Switch im CP-Settings-View. Editor erwartet das so — Settings als Operator-Konzept.

**Mutations + locale-scope today:** Form-Save schreibt YAML-Config global.

**Config dependencies:** Selbst der Config-Driver. Plus jedes Setting ist eine "treibt-andere-Tabs"-Variable.

**Cross-tab ripples:** **Stärkster Ripple-Effekt aller Tabs.** Jede Setting-Änderung beeinflusst mehrere Tabs.

**Feature gaps:**
- **`linkwise.collections` per-Site-Override**: heute alle Sites indexieren dieselben Collections. Multilang-Use-Case: "DE indexiert articles+blog, NL indexiert articles+products"
- **`linkwise.target_collections` per-Site-Override**: gleiche Logik
- **`linkwise.excluded_entries` Origin-Group-Aware**: User excluded EN-Entry, soll DE+NL-Localizations auch automatisch excluden (audit H1)
- **`linkwise.custom_stopwords` per-Locale**: heute eine globale Liste die zu ALLEN Sites added wird. Sinnvoller: "DE custom stopwords: …, EN custom stopwords: …" (audit M3)
- **`linkwise.language` Visibility**: heute prominent als Top-Setting. Auf Multisite ist es nur Fallback (M1 relabel done). Sollte auf Multisite einklappbar in "Advanced" sein damit Editor nicht denkt er muss da was setzen
- **`auto_apply_on_save_enabled` per-Site-Override**: globaler Switch heute. Multilang: DE-Markt will Auto-Apply, EN-Markt manuell.
- **Per-Site-Settings-View**: Statamic kennt das Pattern "Site-Switcher in Settings". Sollte Linkwise auf Multisite-Installs ähnlich verhalten

**Maps to audit:** M1 (done), M2, M3, H1, B5.

---

## Inbound Suggestions Modal (aux)

**Data shown:** Liste aller Source-Entries die linken könnten auf das gewählte Target, mit anchor + sentence_context.

**Editor's expectation on a site:** Locale-scoped (sieht nur same-locale sources) — heute seit Inbound-Fix korrekt.

**Mutations + locale-scope today:** Bulk-Insert + per-source Insert — beide jetzt locale-korrekt.

**Config dependencies:** target_collections, excluded_entries, ignored_pairs.

**Cross-tab ripples:** Insert schreibt Bard-Link in source → Activity-Log, Links Report counts shift.

**Feature gaps:**
- **Source-Locale-Label in Modal**: heute ist nicht ersichtlich auf welcher Locale die Sources liegen. Bei Same-Locale-Filter logisch klar, aber Editor will Bestätigung
- **Cross-Locale-Origin-Aware-Suggestion**: wenn Target ein DE-Entry ist mit EN-Origin, und ein EN-Entry hat anchor-text-match: heute via Filter ausgeschlossen. Aber: Editor könnte WOLLEN dass EN-Source auf DE-Target linkt, weil routing es zur DE-Version umleitet (Statamic-Auto-Routing). Niche aber valider Use-Case. V2-Toggle: "Allow cross-locale-via-origin"
- **Origin-Chain-Display**: Modal-Header zeigt Target-Title, aber nicht "DE-localization of EN-origin XYZ" — wenn relevant
- **`titleLocale`-Surprise** (audit A1 follow-up): wenn das Target ein DE-Entry mit `localizable: false` am Title hat, ist sein Title englisch (inherited vom Origin). Modal zeigt diesen EN-Title als anchor-Kandidat. Editor sieht "Insert link with anchor 'Database Index Design'" obwohl er auf einer DE-Site arbeitet. Sollte mit Hinweis "(Title inherited from EN origin)" gerendert werden, oder Anchor-Candidate explizit lokalisiert (V2)

**Maps to audit:** N1 (`select_across_sites`), A1 (titleLocale UX).

---

## Outbound Suggestions Modal (aux)

**Data shown:** Liste aller Targets die das gewählte Source-Entry suggesten könnte.

**Editor's expectation on a site:** Locale-scoped — heute korrekt durch standard suggest()-Pfad (source ist im $records).

**Mutations + locale-scope today:** Bulk-Insert von outbound links in den source, locale-korrekt.

**Config dependencies:** Same as Inbound.

**Cross-tab ripples:** Same as Inbound.

**Feature gaps:**
- **Target-Locale-Label**: gleich wie Inbound-Modal
- **Same Cross-Locale-Origin-Toggle** wie bei Inbound
- **Per-Match-Type Filter im Modal**: "Title-Match / Keyword-Match / TF-IDF" als Sub-Filter. Multilang-neutral aber UX-relevant
- **Custom-Keywords-Asymmetrie zu Inbound** (audit G1/D5 von Outbound-Seite): das source-Entry (= currently-edited) hat eigene Custom-Keywords im KeywordManager. Outbound-Suggestions ranken Targets höher, deren UUID source's Custom-Keywords matched (separate vom Target-side-Custom-Keyword-Pfad in Inbound). Wenn der Editor auf einer DE-Localization arbeitet aber das Custom-Keyword-Set vom EN-Origin geerbt ist (oder vergessen wurde zu übersetzen), produziert das Outbound semantisch-falsche Suggestions. Inbound hat das Problem nicht symmetrisch — daher hier explizit
- **`titleLocale` der Target-Liste** (audit A1 follow-up): wenn vorgeschlagene Targets DE-Localizations mit EN-Origin-Title sind, zeigt Modal englische Anchors für einen DE-Editor. Gleiches UX-Problem wie Inbound, aber an der Anchor-Liste statt am Modal-Header
- **Custom Keywords als Outbound-Entry-Property**: Editor sollte im Outbound-Modal direkt sehen ob Source-Entry Custom-Keywords definiert hat. Aktuell verteckter Faktor in Suggestion-Ranking

---

## Cross-Tab-Interaktionen

Die folgenden Entscheidungen rippeln über mehrere Tabs. Wenn der User V1.2 plant, muss er für jede Achse die Konsequenz aufschreiben:

### A — Locale-Filter-UI als Standard-Pattern

Wenn Locale-Filter in Links Report eingeführt wird, sollten Broken Links / Activity / Custom Keywords / AutoLink-Rule-Preview den gleichen Filter benutzen. Sonst inkonsistent. Eine geteilte `<LocaleFilter>` Component (siehe `locale_filter_tabs_track.md` memory).

### B — Auto-Link Rules per-Locale

Wenn Rules locale-scope kriegen (audit F1):
- AutoLink Tab — neue Rule-Property "locales"
- Activity Log — pro Apply-Rule-Snapshot wird "executed on de" angezeigt
- Links Report Counts — Rule-Apply ändert nur scope-Locale-Entries
- Broken Links — Rule-erzeugte Links bei broken-target nur in der relevanten Locale flagged
- Settings — `auto_apply_on_save_enabled` per-Site Override macht erst Sinn wenn Rules per-Locale sind

### C — Origin-Group-Awareness

Wenn Excluded-Entries / Custom-Keywords / Activity-Revert origin-aware werden (audit H1, G1):
- Custom Keywords — "copy from origin"-UI
- Excluded Entries — "exclude entire origin group" als Option
- Activity Revert — "revert only this locale / all 3"
- Links Report — Origin-Group-Collapse-View
- Inbound Modal — zeigt Origin-Chain-Hinweis

### D — Per-Site-Collections-Override

Wenn `linkwise.collections` per-Site override kriegt:
- Overview — Counts pro Site differieren
- Links Report — Listing-Inhalt ändert sich pro Site
- AutoLink Rule-Preview — Rule kann nur in scope-Collection-Sites feuern
- Indexer — komplexere Build-Logik (pro Site die richtigen Collections walken)

### E — Custom Stopwords per-Locale

Wenn `linkwise.custom_stopwords` per-Locale wird (audit M3):
- Settings — separate Felder pro Locale
- KeywordExtractor — TF-IDF per-Locale-Stopwords (heute global)
- TextNormalizer — `tokenizeWithMappingFor` zieht die richtige Liste pro Locale
- Multi-Locale-Apply weiterhin korrekt

### F — `titleLocale` UX-Surfacing

`EntryRecord::$titleLocale` (eingeführt in PR #101 audit A1) ist Daten-seitig richtig — Engine stemt den Title in der korrekten Sprache. Aber UI-Schicht zeigt den Title trotzdem so wie er ist (auf einer DE-Localization mit non-localizable Title → englischer Title). Konsequenz:

- **Inbound Modal** — Modal-Header zeigt englischen Title für eine DE-Localization-Target. Editor verwirrt
- **Outbound Modal** — vorgeschlagene Target-Anchors sind englisch in einer DE-Editor-Session
- **Links Report** — Title-Spalte zeigt origin-language statt locale-language
- **Activity Log** — recorded source-entry-title kommt mit `titleLocale` mismatch
- Fix-Pattern: pro Render-Stelle Hinweis "(Title inherited from <origin-locale>)" ODER Render mit gray-out + Tooltip

Einzelne Stellen wären ein eigener UX-Polish-Sub-PR — niche aber sichtbar sobald jemand multilingual seriously testet.

### G — Bulk-Snapshot Schema-Evolution für Locale-Filter

Heutiger Activity-Log-Fix (2026-05-24) speichert `source_entry_title` + `target_entry_title` im Snapshot. Wenn aber V1.2 einen Locale-Filter für Activity-Log einführt, braucht der Filter ZUSÄTZLICH `source_entry_locale` + `target_entry_locale` im Snapshot. Sonst kann der Filter auf alten Snapshots (vor V1.2-Schema-Bump) nicht greifen → "All localees" bleibt einzige Option für historische Snapshots.

Decision-Point: schreibt V1.2-Locale-Filter-Sub-PR die Schema-Erweiterung gleich mit (locale-fields in `appendWrittenItem`), oder akzeptiert er dass Pre-V1.2-Snapshots nicht filterbar sind?

### H — `select_across_sites` Bard-Config Respect

Wenn Linkwise das Bard-`select_across_sites: true` config respektiert (audit N1):
- Inbound + Outbound Modals — Toggle "Show cross-locale targets when blueprint allows"
- Settings — global default vs per-Field-Override
- AutoLink Rules — "allow cross-locale for this rule"

---

## Was NICHT in diesem Dokument steht (bewusst rausgehalten)

- Pre-Existing nicht-Multilang-Lücken (Aerni-Style-Marketing-Polish, dunklere Modal-Pattern-Refactors, etc.) — separate tracks
- Performance-Optimierungen die nicht aus Multilang resultieren
- AI/LLM-Features (V2)
- Marketplace-Submission-Mechanics
