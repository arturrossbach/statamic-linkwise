# Linkwise Tab-Gap-Analysis — LinkWhisper + LinkBoss Vergleich

**Erstellt:** 2026-05-27  
**Quellen:** LINK-WHISPER-FEATURE-INVENTORY.md (2026-03-30), LINKBOSS-FEATURE-INVENTORY.md (2026-05-26), LINKWISE-V2-MASTER-PLAN.md  
**Methode:** Max 5 Findings pro Tab. Status-Werte: `V1X-POLISH` / `V1.3-Track` / `V2-MUST` / `V2-SHOULD` / `V3+-TBD` / `GARBAGE`  
**Tie-Break:** V2-Master-Plan §6 GARBAGE schlägt jedes Inventory-HIGH-Label.

---

## 1. OVERVIEW TAB

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| O-1 | **Cannibalization-Detection** fehlt komplett — kein Counter, kein Drill-down | LB-Inventory §4.03: „When the same anchor text links to multiple different pages, you send conflicting signals. Auch als Free-Tool. Status Linkwise: MISSING. Priorität V2: HIGH" | **V2-MUST** |
| O-2 | **Health-Metric-Badges (Great/OK/Warning)** — Overview zeigt Rohe Zahlen ohne Kontext-Signal | LW-Demo: „Link Health section: Link Coverage %, Link Quality Score, External Site Focus %, Anchor Length Score. Each health metric has a badge (Great/OK/Warning) and links to filtered report" | **V2-SHOULD** |
| O-3 | **Dead-End-Counter** (outbound=0 entries) fehlt in Overview-Cards | LB-Inventory §4.02: „Dead-end pages have no outgoing internal links, trapping visitors and preventing link equity distribution [LB-claim]. Status Linkwise: UNKLAR — Linkwise hat outbound-Count pro Entry, aber kein Dead-End Report als eigene Page." | **V3+-TBD** |
| O-4 | **Per-Locale Stats** — orphaned_count/most_linked werden cross-locale berechnet, editor-verwirrend | V1.3-Track Memory `per_locale_overview_stats.md`: „orphaned_count / most_linked / least_linked werden auf multilang installs cross-locale berechnet (legitim aber editor-confusing). Locale-Filter-Toggle für Overview-Cards, ~4-6h" | **V1.3-Track** |
| O-5 | **Link-Flow-Score** (composite number) | LB-Inventory §4.05: „exclusive Link Flow Score system [LB-claim] — single-number health metric pro Site/Page." V2-Master-Plan §6: **GARBAGE** — „Link-Flow-Score composite (no study backing)" | **GARBAGE** |

---

## 2. LINKS REPORT TAB

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| L-1 | **Anchor-Edit vor Insertion** *(cross-cutting: SuggestionModal, alle Tabs)* — Nutzer kann vorgeschlagenen Anchor-Text nicht anpassen bevor er einfügt | LW-Inventory §1.04: „Before accepting a suggestion, the user can edit the anchor text to adjust length, wording, or relevance. Priority: IMPORTANT." LB-Inventory §5.02: „cross-source-confirmation: zwei Konkurrenten haben das, wir nicht. Priorität V2: HIGH" | **V2-MUST** |
| L-2 | **Expandable Detail Rows** — kein Inline-Drill-down aller Inbound/Outbound-Anchors per Row | LW-Inventory §2.06: „Click expand on any report row to see: all inbound source posts + anchor texts, all outbound target posts + anchor texts, all external links. Each with delete buttons. Priority: IMPORTANT, Status: NOT STARTED" | **V2-SHOULD** |
| L-3 | **Skip-Intro-Sentences Setting** — erste N Sätze von Vorschlägen ausschließen fehlt | LW-Inventory §1.08: „Number of Sentences to Skip — configurable count of opening sentences excluded from suggestions to avoid overloading intro paragraphs with links. Priority: IMPORTANT, Status: NOT STARTED" | **V1.3-Track** |
| L-4 | **CSV Export** der kompletten Links-Tabelle | LW-Inventory §2.14: „Export all internal linking data (links report, domains report, etc.) to CSV format for external analysis. Priority: IMPORTANT, Status: NOT STARTED" | **V2-SHOULD** |
| L-5 | **Report-Filterung: Keyword-Suche + Category** — Collection-Filter existiert, aber kein Freitext-Titel-Suche oder Category-Filter | LW-Inventory §2.04: „Filter the links report by post type, categories/tags, or keyword search to narrow results. Priority: IMPORTANT, Status: PARTIAL (collection filter exists)" | **V1.3-Track** |

---

## 3. BROKEN LINKS TAB

> Linkwise ist hier dem Markt voraus. LB-Inventory §4.06: „Status LinkBoss: Pending (nicht released!). Status Linkwise: DONE." Dennoch bestehen UX-Gaps.

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| B-1 | **Status-Code-Multiselect-Filter** fehlt — kein „zeige nur 404 / nur Timeouts" | LW-Demo: „Filter bar: Delete Selected, Status Code multi-select, All Types, All Categories. Status codes: Server not Found, Connection Failed, Timeout, SSL Error, 301, 302, 400, 401, 403, 404, 405, etc." | **V1.3-Track** |
| B-2 | **Retry-Count nicht konfigurierbar** — BrokenLinkChecker hat 2 Retries (config-backed, `broken_links.retries`), kein UI-Setting; User kann Sensitivität nicht anpassen | LW-Inventory §2.09: „Double-checks findings to avoid false positives." Verifiziert: `BrokenLinkChecker.php:363` `->retry($this->retries, 100, throw: false)` mit Default 2 — Infra ist da, UI fehlt. | **V1.3-Track** |
| B-3 | **Automatischer Hintergrund-Scan (Cron)** — aktuell nur manuell ausgelöst | LW-Inventory §3.14: „WordPress cron scans 10-20 links every 5 minutes automatically in the background. Alternative to manual scanning. Useful for large sites to avoid timeouts. Priority: IMPORTANT" | **V2-SHOULD** |
| B-4 | **Batch-Delete ausgewählter Broken-Links** — kein „alle selektierten löschen" | LW-Demo: „Hover on Broken URL: Ignore, Edit (inline URL editing with confirm/cancel)." + „Delete Selected" Batch-Action sichtbar in Filterbar | **V1.3-Track** |
| B-5 | **Reset-Scan-Button** — kein First-Time-Setup-Reset | LW-Inventory §2.10: „First-time setup button to initialize the broken link scan data. Resets and restarts the scanning process. Priority: IMPORTANT, Status: NOT STARTED" | **V1.3-Track** |

---

## 4. DOMAINS TAB

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| D-1 | **Per-Domain Link-Attribute-Config** (nofollow/dofollow/sponsored/new-tab) fehlt | LW-Inventory §4.09: „In the Domain Settings section, configure link attributes per external domain. Set nofollow, dofollow, or sponsored for all links to that domain site-wide. Priority: NICE." + §4.10: „Configure per external domain whether links open in the same tab or a new tab. Priority: NICE" | **V2-SHOULD** |
| D-2 | **Domain-Drill-Down** — kein Inline-Dropdown mit Post-Titeln / URLs / Anchor-Texten | LW-Demo: „Columns: Domain, Applied Domain Attributes, Posts (number + dropdown), Links (number + dropdown). Dropdowns show post titles, URLs, anchor texts." | **V2-SHOULD** |
| D-3 | **Mark-as-Internal** für Subdomains / CDNs / Affiliate-Cloaks | LW-Inventory §4.11: „Specify URLs that should be treated as internal links even though they are technically external (e.g., cloaked affiliate links, CDN domains, subdomains). Priority: NICE, Status: NOT STARTED" | **V2-SHOULD** |
| D-4 | **CSV Export** pro Domain | LW-Inventory §2.14: „Export all internal linking data (links report, domains report, etc.) to CSV format for external analysis. Priority: IMPORTANT" | **V2-SHOULD** |
| D-5 | **Batch-Delete ausgewählter Domain-Einträge** | LW-Demo: „Selectable rows with 'Delete Selected' batch action" | **V1.3-Track** |

---

## 5. AUTO-LINK TAB

> Linkwise ist beim Kern (Keyword→URL-Regeln) den Konkurrenten **deutlich voraus**. LB-Inventory §7.03: „Status Linkwise: DONE. Linkwise deutlich voraus. Marketing-Punkt."

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| A-1 | **Collection-/Category-Restriction per Regel** — Regel greift auf gesamte Site, kein Scope | LW-Inventory §3.09: „Limit auto-link insertion to posts within designated categories/tags only. Priority: IMPORTANT, Status: NOT STARTED" | **V1.3-Track** |
| A-2 | **Regel-Priorität** (numerisch) bei Überlappung mehrerer Regeln | LW-Inventory §3.06: „Set a numeric priority for each auto-link rule. Higher numbers = higher priority. Determines which rule wins when multiple rules match the same text. Priority: NICE" | **V2-SHOULD** |
| A-3 | **Datum-basierte Einschränkung** (nur Posts nach Datum X) | LW-Inventory §3.07: „Restrict auto-links to posts published after a specific date, with a date selector. Useful for avoiding retroactive changes to old content. Priority: NICE" | **V2-SHOULD** |
| A-4 | **Max-Links-Cap per Regel** (z.B. max. 50 Inserts für diese Regel) | LW-Inventory §3.10: „Set a maximum number of total auto-links that a single rule can create across the site. Priority: NICE" | **V2-SHOULD** |
| A-5 | **External-URL-Support** in Regeln (Affiliate-Links etc.) | LW-Inventory §3.12: „Auto-linking rules can point to external URLs, not just internal pages. Useful for affiliate links or partner sites. Priority: NICE" | **V3+-TBD** |

---

## 6. TARGET KEYWORDS TAB

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| K-1 | **GSC-Auto-Population** — Target Keywords aus echten Search-Queries befüllen | LW-Inventory §5.09: „Connect Google Search Console to auto-populate target keywords with actual search queries your pages rank for. Priority: NICE." LB-Inventory §6.01: „GSC-Powered Analytics (full depth) [LB-claim]... Striking-Distance-Report — Entries auf Google-Position 11-20 surfacen + auto-suggest welche internal Links sie auf Page 1 boosten könnten." Priorität: HIGH (V2-Master-Plan) | **V2-MUST** |
| K-2 | **Striking-Distance-Ansicht** (GSC-Positionen 11–20) als Priority-Signal | LB-Inventory §6.01: „Striking-Distance-Report — Entries auf Google-Position 11-20 surfacen + auto-suggest welche internal Links sie auf Page 1 boosten könnten. Das ist der SEO-Power-Workflow, größter Conversion-Lever." | **V2-MUST** *(abhängig von K-1)* |
| K-3 | **Bulk-Keyword-Assign** — Keywords für mehrere Entries gleichzeitig setzen/löschen | LW-Inventory §5.05: „Define SEO target keywords for each post. Keywords improve suggestion quality. Bulk editing across all posts. Priority: IMPORTANT" | **V2-SHOULD** |
| K-4 | **Custom Stopwords Liste konfigurierbar** — kein UI-Setting für eigene Stopp-Wörter; aktuell nur Code-Config | LW-Inventory §4.04: „Pre-populated array of common words excluded from suggestion matching. Customizable — users can add or remove words. Priority: IMPORTANT, Status: NOT STARTED" | **V1.3-Track** |
| K-5 | **Exclude-Entry-from-Suggestions** per Entry — kein Blacklist-Flag | LW-Inventory §4.07: „Blacklist individual pages or posts so they never appear in suggestions and are excluded from all Link Whisper services. Priority: IMPORTANT, Status: NOT STARTED" | **V1.3-Track** |

---

## 7. ACTIVITY TAB

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| Ac-1 | **Bulk-Batch-Undo** — kein „gesamten letzten Batch rückgängig machen" | LB-Inventory §3.03: „One-click rollback — delete entire bulk batches instantly [LB-claim]. Status Linkwise: TEILWEISE — pro-Entry Undo aus Activity-Log existiert, aber kein 'undo last bulk batch as group'. Komplexität: Niedrig (3-5 Tage) — Infra ist da." | **V2-SHOULD** |
| Ac-2 | **Locale-Filter im Activity-Log** | V1.3-Track Memory `locale_filter_tabs_track.md`: „Select-Filter für Links-Report / AutoLink-Rule-Preview / Broken-Links / Activity-Log. Shared LocaleFilter Vue-Komponente + ?locale= Query-Param. Single-Site hide. ~3-4h." | **V1.3-Track** |
| Ac-3 | **CSV Export** des Activity-Logs | LW-Inventory §2.14: „Export all internal linking data (links report, domains report, etc.) to CSV format for external analysis. Priority: IMPORTANT" | **V2-SHOULD** |
| Ac-4 | **Click-Tracking / Analytics** — welche Links werden tatsächlich angeklickt? | LW-Inventory §2.13: „Tracks which internal links visitors actually click. Shows: total clicks per page for a timeframe, most-clicked link per page, line graph over time, per-link breakdown (URL, anchor text, total clicks, source post). Priority: NICE" | **V3+-TBD** |
| Ac-5 | **Monthly Report Card** — automatischer Monatsbericht mit Verbesserungs-Empfehlungen | LW-Inventory §2.15: „Automated report delivered every 30 days summarizing internal linking performance with actionable improvement recommendations and one-click fixes. Priority: NICE" | **V3+-TBD** |

---

## 8. URL CHANGER TAB

> Linkwise ist hier dem Markt voraus. LB-Inventory §7.01: „Safe search & replace feature [hard-fact, Roadmap-in-progress bei LinkBoss]. Status Linkwise: DONE. Linkwise voraus." Daher weniger Gaps.

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| U-1 | **Title-basierte Entry-Suche** im URL-Changer-Input | V1.3-Track Memory `v13_url_changer_title_search.md`: „Title-basierte Entry-Suche im URL Changer Input. Decision-Point: gehört das in URL Changer oder ins Inbound-Modal? V1.3-Sprint-Planung-Item." | **V1.3-Track** |
| U-2 | **Wildcard/Pattern-Matching** (z.B. `/blog/old-*` → `/artikel/old-*`) | LW-Demo: „Wildcard matching for ignore lists (example.com/*)" — Analogie zum URL Changer anwendbar | **V2-SHOULD** |
| U-3 | **Undo / Revert nach Apply** — URL-Changes sind aktuell permanent | LB-Inventory §3.03: „One-click rollback — delete entire bulk batches instantly [LB-claim]" — apply-batch via Activity-Log batch_id rückgängig machen | **V2-SHOULD** |

---

## NEUER TAB — NOCH NICHT EXISTENT: CANNIBALIZATION REPORT

> Fehlt als eigenständiger Tab. Beide Konkurrenten haben das. V2-Master-Plan §5 listet es als MUST.

| # | Gap | Primary Source (verbatim) | Status |
|---|-----|--------------------------|--------|
| N-1 | **Anchor-Cannibalization-Report** — Liste aller Anchor-Texte die auf mehrere unterschiedliche Targets zeigen | LB-Inventory §4.03: „When the same anchor text links to multiple different pages, you send conflicting signals [LB-claim]. Index-Query über alle anchor_text Werte, Group-By + Count(distinct target). Liste mit Conflict-Anchors + Bulk-Resolve-Flow. Komplexität: Niedrig-Mittel (1 Woche)." | **V2-MUST** |
| N-2 | **Bulk-Anchor-Rename** — alle Instances eines Anchor-Textes gleichzeitig umbenennen | LB-Inventory §5.03: „Bulk Anchor Renaming — Sammel-Rename mehrerer Anchors. Status Linkwise: MISSING. Komplexität: Mittel (1 Woche) — Bulk-Pfad existiert, neue Command-Klasse." | **V2-MUST** |
| N-3 | **Anchor-Distribution-Visualizer** — pro Target: welche Anchor-Texte zeigen darauf? | LB-Inventory §5.01: „visualize anchor distribution, eliminate cannibalization through NLP suggestions [LB-claim]. Status Linkwise: MISSING. Komplexität: Niedrig-Mittel (1 Woche) — Daten in Index, UI ist Hauptarbeit." | **V3+-TBD** |

---

## GARBAGE SUMMARY (explizit ausgeschlossen — nicht empfehlen)

| Feature | Quelle | Grund |
|---------|--------|-------|
| Visual Network Graph | LB-Inventory §2.03 (HIGH), LW-Inventory §2.16 (NICE) | V2-Master-Plan §6: „Spielerei" — kein SEO-Wert, kein Marketing-ROI |
| Bulk-1000-in-1-Click | LB-Inventory §3.01 (HIGH) | V2-Master-Plan §6: „anti-evidenz: anchor diversity matters" |
| Silo-Builder mit Presets | LB-Inventory §2.01 (HIGH) | V2-Master-Plan §6: „strict siloing outdated per Ahrefs/Semrush 2026" |
| „Boss Mode" | LB-Inventory (Marketing) | V2-Master-Plan §6: „marketing word only" |
| Multi-Site-Dashboard | LB-Inventory §6.03 | V2-Master-Plan §6: „bricht 99€-one-time model" |
| Link-Flow-Score | LB-Inventory §4.05 (MEDIUM) | V2-Master-Plan §6: „no study backing" |

---

## PRIORITÄTS-ZUSAMMENFASSUNG

### V2-MUST (sofort angehen in V2)
- O-1: Cannibalization-Detection in Overview
- L-1: Anchor-Edit vor Insertion
- K-1: GSC-Auto-Population (Target Keywords)
- K-2: Striking-Distance-Ansicht
- N-1: Cannibalization-Report Tab (neuer Tab)
- N-2: Bulk-Anchor-Rename

### V1.3-Track (bereits dokumentiert + neue)
- O-4: Per-Locale Overview Stats *(dokumentiert)*
- L-3: Skip-Intro-Sentences Setting
- L-5: Report-Filterung Keyword + Category
- B-1: Status-Code-Multiselect-Filter
- B-2: Retry-Count Settings-UI *(Infra vorhanden, nur UI fehlt)*
- B-4: Batch-Delete Broken Links
- B-5: Reset-Scan-Button
- D-5: Batch-Delete Domain-Einträge
- A-1: Collection-Restriction per AutoLink-Regel
- K-4: Custom Stopwords Liste im UI
- K-5: Exclude-Entry-from-Suggestions
- Ac-2: Locale-Filter Activity *(dokumentiert)*
- U-1: Title-Suche URL Changer *(dokumentiert)*

### V2-SHOULD
- O-2: Health-Metric-Badges
- L-2: Expandable Detail Rows
- L-4: CSV Export (Links, Domains, Activity)
- D-1: Per-Domain Attribute-Config
- D-2: Domain Drill-Down Dropdowns
- D-3: Mark-as-Internal
- A-2: Regelpriorität AutoLink
- A-3: Datum-Einschränkung AutoLink
- A-4: Max-Links-Cap AutoLink
- K-3: Bulk-Keyword-Assign
- Ac-1: Bulk-Batch-Undo
- U-2: Wildcard URL Changer
- U-3: Undo nach URL Apply

### V3+-TBD
- O-3: Dead-End-Counter
- A-5: External URLs in AutoLink-Regeln
- Ac-4: Click-Tracking
- Ac-5: Monthly Report Card
- N-3: Anchor-Distribution-Visualizer

---

## LINKWISE-ORIGINAL-IDEEN (kein Competitor-Beleg — separat bewerten)

> Diese Items haben KEIN direktes Pendant in LinkWhisper/LinkBoss-Inventories. Kein Competitor-Analog gefunden. Nicht in Haupt-Gap-Tabelle gelistet um `feedback_hard_facts_no_fantasy` einzuhalten. Separate Bewertung durch User erforderlich.

| Idee | Herkunft | Potenzial |
|------|----------|-----------|
| **Keyword-Effectiveness-Tracking** — wie viele Links wurden aufgrund eines Keywords inseriert? | Abgeleitet aus LB-Inventory §6.01 Activity-Log-Ranking-Delta-Gedanken; kein Competitor hat das | **Differentiator-Kandidat** — setzt Linkwise-Activity-Log-Vorteil strategisch ein |
| **Bulk-URL-Import via CSV** (mehrere URL-Paare auf einmal in URL Changer) | Eigene Idee, kein Inventory-Beleg | Niedriges Risiko; einfach zu implementieren wenn URL Changer erweitert wird |
