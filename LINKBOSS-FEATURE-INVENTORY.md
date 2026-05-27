# LinkBoss: Complete Feature Inventory for Linkwise

**Compiled:** 2026-05-26
**Sources:** linkboss.io (homepage, use-cases, bulk-tool, roadmap, free-tools, CMS, vs-LinkWhisper blog, best-tools blog), Product Hunt (4.9★ 17 reviews), skywork.ai review, bymilliepham.com review, Web-Search-Triangulation (Trustpilot, G2, Reddit/SEO).
**Bias caveat:** linkboss.io claims are LinkBoss-self-marketing — labeled `[LB-claim]`. Triangulated 3rd-party signals labeled `[3rd-party]`. Hard-numerical-fact (pricing, CMS list, roadmap status) labeled `[hard-fact]`.

---

## 0. Executive Verdict — Knallhart

### 0.1 Was LinkBoss anders macht (objektiv)
- **Embeddings statt TF-IDF:** „uses vector embeddings to ensure every bulk link is contextually relevant" [LB-claim]. Linkwise nutzt TF-IDF + Stemmer. Das ist die zentrale technische Differenz — alles andere bei LinkBoss ist Folge davon (Bulk-1000-Insert, Smart-Generator, „semantic accuracy").
- **Cloud-SaaS-Architektur:** „All heavy AI processing runs on our servers" [LB-claim]. Linkwise ist self-hosted Composer-Package — keine recurring infrastructure cost beim Kunden, aber auch kein „kostenlos LLM ausrollen ohne Kundenkosten" möglich.
- **Subscription + Credits-Modell:** $11–$549/mo, jeder Link = 1 Credit [hard-fact]. Linkwise ist 99€ one-time (Marketplace) [hard-fact]. Unterschiedliche Ökonomien, unterschiedliche Inzentive.
- **Multi-CMS, aber WordPress-tief:** „Full automatic" nur WordPress + Shopify. Webflow/Squarespace/Wix/Ghost = „semi-automatic: LinkBoss suggests links, you copy and paste them" [hard-fact]. Linkwise = Statamic-tief, native.

### 0.2 AI-Reality-Check (gegen die „AI MUSS funktionieren"-Prämisse)

User-Prämisse: „LinkBoss zeigt dass AI funktioniert, also MUSS Linkwise AI kriegen." Verifikation gegen 3rd-party-Quellen:

| Signal | Quelle | Aussage (verbatim) |
|---|---|---|
| Positiv | Product Hunt (4.9/5, 17 reviews) | „prioritizes semantic meaning for link suggestions", „creates a topical relevant paragraph" |
| Positiv | skywork.ai | „AI learns over time, providing increasingly accurate recommendations" |
| **Negativ** | skywork.ai | **„AI suggestions occasionally need manual review for niche content"** |
| **Negativ** | skywork.ai | **„Over-aggressive use can create over-optimization risk if unchecked"** |
| **Negativ** | WebSearch (generic) | **„Users occasionally found irrelevant suggestions that needed to be rejected"** |
| **Negativ** | Product Hunt | **„repetitive suggestions at times"** |
| **Kritisch** | Drittquellen-Recherche | **„Link Permanence Concern: links are inserted via JavaScript, meaning if you cancel your subscription, the links may disappear"** — direkter Widerspruch zu LinkBoss-Claim „Links stay permanent forever" |

**Verdict:** LinkBoss-AI ist **kommerziell tragfähig, nicht magisch.** Reviewer berichten dieselbe Klasse von Problemen wie LinkWhisper („irrelevant suggestions, need rejection") — nur in geringerer Dichte. Die Houthi-Sentence-Klasse (`feedback_ai_required.md`) ist im Markt nicht catastrophic; Kunden tolerieren ~10% Junk wenn der Rest spart. **Das validiert AI-Pivot für Linkwise V2 — aber nicht als Magic-Replacement, sondern als hybrid Re-Ranker auf TF-IDF-Kandidaten.**

JavaScript-Link-Insertion ist auch ein **strategisches Defensiv-Argument für Linkwise:** unsere Links sind Bard-Mark-Mutationen direkt im Entry-Storage. Cancel-resistant by design. Das ist ein verkaufbares Differenzierungs-Argument gegen LinkBoss.

---

## 1. AI / SEMANTIC RELEVANCE

### 1.01 Vector-Embedding-Based Suggestion Engine
- **Description:** „NLP & semantic AI to understand what your content actually means" [LB-claim]. Verwendet Vector Embeddings für Kontext-Verständnis. Behauptet „99.7% semantic accuracy" + „53% more relevant than LinkWhisper" [LB-claim, unverified].
- **Status LinkBoss:** Core feature
- **Status Linkwise:** **MISSING** — Linkwise nutzt TF-IDF + Stemming (frequency_stopwords_track als Verbesserung pending)
- **Priorität V2:** **CRITICAL**
- **Empfohlene Linkwise-Umsetzung:** Hybrid-Architektur — TF-IDF als Candidate-Filter (top-50), Embeddings (lokales Modell ODER OpenAI/Anthropic-API) als Re-Ranker. Houthi-Sentence-Problem aus `feedback_ai_required.md` adressieren durch (a) Threshold-Floor auf Embedding-Score, (b) TF-IDF-Anchor-Quality bleibt Gate für Anchor-Auswahl. Kein reines Embedding-Lookup.
- **Komplexität:** Hoch (3-4 Wochen)
- **Ökonomie-Frage:** API-Kosten beim Kunden ODER lokales Embedding-Modell (ollama nomic-embed-text)? Entscheidet zwischen „BYO-API-Key" Modell und „läuft ohne Kosten" Modell.

### 1.02 Smart Link Generator (paragraph creation)
- **Description:** „When no obvious interlinking opportunities exist within your existing content, LinkBoss can generate new contextual paragraphs that naturally incorporate internal links" [LB-claim]. 2 credits pro generierter Paragraph.
- **Status LinkBoss:** Core feature, gelobt in PH-Reviews („creates a topical relevant paragraph")
- **Status Linkwise:** **MISSING**
- **Priorität V2:** **HIGH**
- **Empfohlene Linkwise-Umsetzung:** LLM-Call (BYO-Key) der für ein Orphan-Target einen 2-3-Satz-Paragraph generiert und in den Quell-Entry einfügt. Bard-Mutator existiert bereits — neuer „insertGeneratedParagraph"-Pfad. WHY-Differenzierung: Anders als embedding-relevance (gescheitert in V1), ist Paragraph-Generation ein klar bewertbarer Output („Satz passt oder passt nicht").
- **Komplexität:** Mittel (1-2 Wochen wenn API-Key-Infra steht)

### 1.03 NLP-Optimized Anchor Selection
- **Description:** „NLP picks the most natural anchor based on sentence structure" [LB-claim]. „Anchors flow naturally within sentences. They won't seem forced." [LB-claim]
- **Status LinkBoss:** Beworben, Reviewer-Confirmation gemischt
- **Status Linkwise:** **TEILWEISE** — Anchor-Quality aus Sister-Bug-Saga (5 Runden) ist „good enough", aber kein NLP-Grammar-Check. Junk-Anchors („richtige", „funktioniert") wurden via Stopwords gefixt, nicht via Linguistik.
- **Priorität V2:** MEDIUM
- **Empfohlene Linkwise-Umsetzung:** POS-Tagging (php-nlp-tools oder spaCy via Python-microservice) — Anchor MUSS Noun-Phrase sein, nicht Verb/Adjektiv allein. Würde frequency_stopwords-Track ergänzen statt ersetzen.
- **Komplexität:** Mittel-Hoch (PHP-NLP-Tooling ist mager — eventuell Python-Sidecar)

---

## 2. SILO / TOPIC-CLUSTER ARCHITECTURE

### 2.01 Silo-Builder mit Preset-Topologien
- **Description:** „one-click silo presets" mit 4-5 Typen [hard-fact von Marketing]: Hybrid Circle Silo, Reverse Silo, Priority Silo, Serial Silo, Hub Page Silo. Build „topic clusters within minutes" um Pillar-Pages [LB-claim].
- **Status LinkBoss:** Industry-First-Marketing-Punkt, oft positiv erwähnt in Reviews („Topic cluster + silo builder included — most competitors charge extra")
- **Status Linkwise:** **MISSING** — komplett.
- **Priorität V2:** **HIGH**
- **Empfohlene Linkwise-Umsetzung:** Pillar-Entry definieren → Linkwise wählt Top-N thematisch verwandte Entries → Schema-Wahl: Hub (alle → Pillar), Serial (1→2→3→Pillar), Reverse (Pillar → alle). UI = neuer Tab im Linkwise-CP. Backend: bulk-write-path Standard (`bulk_write_path_standard.md`) für jede Topologie. Eigenständiger Differentiator weil SEO-Konzept, nicht Tool-Konzept.
- **Komplexität:** Mittel-Hoch (2-3 Wochen)

### 2.02 Custom Network Builder (eigene Silo-Topologien)
- **Description:** „Create your own designed interlinking silo network. Use silo presets if needed." [hard-fact, Roadmap-completed]
- **Status LinkBoss:** Released
- **Status Linkwise:** **MISSING**
- **Priorität V2:** MEDIUM (folgt 2.01)

### 2.03 Visual Silo Mind-Map (real-time graph)
- **Description:** „A dynamic visual map that generates and displays the silo network in real-time" [hard-fact, Roadmap-completed]. „Interactive network graph showing website interlinking structure to identify isolated clusters" [LB-claim].
- **Status LinkBoss:** Released, in Reviews als „Site Visualizer gives a full graph view of your link structure" gelobt
- **Status Linkwise:** **MISSING** — Linkwise hat Overview-Stats (orphaned_count etc) aber keinen Graph
- **Priorität V2:** **HIGH** (visual value-prop für Marketing-Screenshots ist hoch)
- **Empfohlene Linkwise-Umsetzung:** d3.js oder cytoscape.js force-directed graph. Daten existieren in `inbound`/`outbound`-Indizes. Zoom/Cluster-Highlight/Orphan-Hervorhebung. UI-Heavy aber Daten sind da.
- **Komplexität:** Mittel (1-2 Wochen)

---

## 3. BULK / SCALE-OPERATIONS

### 3.01 Bulk Auto-Interlinking 1000-2000 Links pro Click
- **Description:** „Build up to 1,000 interlinks in one click" / „Users can create up to 2000 contextual internal links in bulk with one click" [LB-claim]. „200 URLs simultaneously" auf Scale-Plan.
- **Status LinkBoss:** Core USP, Marketing-Hauptpunkt
- **Status Linkwise:** **TEILWEISE** — Linkwise hat BulkAutoLink (rule-basiert), aber keinen „1000-in-1-click"-Flow für ganze Site. Eher per-rule oder per-entry-batch.
- **Priorität V2:** HIGH (Marketing-Punkt)
- **Empfohlene Linkwise-Umsetzung:** „Bulk Optimize Entire Site"-Button: Linkwise scant alle Entries, sammelt alle high-confidence Suggestions, präsentiert Preview-Table mit 1000+ Vorschlägen, User selektiert/deselektiert, ein Click → BulkApplyCommand mit Standard-Compliance. Bulk-write-path-Standard (`bulk_write_path_standard.md`) ist bereits da. Hauptarbeit: UI für 1000-Zeilen-Preview-Table mit Pagination/Filter.
- **Komplexität:** Mittel (1-2 Wochen — Backend trivial, UI nicht)

### 3.02 Full Paragraph-Level Preview
- **Description:** „Full paragraph-level preview before anything goes live" [LB-claim]
- **Status LinkBoss:** Released
- **Status Linkwise:** **DONE** (Diff-Preview existiert)
- **Priorität:** —

### 3.03 One-Click Bulk Rollback
- **Description:** „One-click rollback — delete entire bulk batches instantly" [LB-claim]
- **Status LinkBoss:** Released
- **Status Linkwise:** **TEILWEISE** — pro-Entry Undo aus Activity-Log existiert, aber kein „undo last bulk batch as group"
- **Priorität V2:** MEDIUM
- **Empfohlene Linkwise-Umsetzung:** ActivityLog hat bereits batch_id-Konzept via Sister-Audit. Neuer Button „Undo Batch" der alle Entries einer batch_id revertiert in einem Bulk-Job.
- **Komplexität:** Niedrig (3-5 Tage) — Infra ist da.

### 3.04 Bulk Link Remover (Fresh-Start-Reset)
- **Description:** „One click all internal, external link remover to do a fresh start" [hard-fact, Roadmap-in-progress]
- **Status LinkBoss:** In Progress
- **Status Linkwise:** **MISSING**
- **Priorität V2:** LOW (destructive, support-burden hoch, Confirmation-UX kritisch)

---

## 4. LINK-HEALTH / AUDIT

### 4.01 Orphan-Page-Detection
- **Description:** „Orphan pages have no internal links pointing to them" — Detection-Tool im Free-Tier (50 pages limit) sowie im Paid-Produkt
- **Status LinkBoss:** Released (auch als Free-Tool)
- **Status Linkwise:** **DONE** (V1.3-Track sieht per-locale-Variante vor)

### 4.02 Dead-End-Page-Detection
- **Description:** „Dead-end pages have no outgoing internal links, trapping visitors and preventing link equity distribution" [LB-claim]
- **Status LinkBoss:** Released
- **Status Linkwise:** **UNKLAR** — Linkwise hat outbound-Count pro Entry, aber kein „Dead-End Report" als eigene Page. Wahrscheinlich derivable.
- **Priorität V2:** MEDIUM
- **Empfohlene Linkwise-Umsetzung:** Neuer Report-Tab „Dead-Ends" — Query `outbound_count = 0 AND status = published`. Plus Suggestion-Auto-Run für jedes Dead-End. Trivial.
- **Komplexität:** Niedrig (3-5 Tage)

### 4.03 Anchor-Text-Cannibalization-Detection
- **Description:** „When the same anchor text links to multiple different pages, you send conflicting signals" [LB-claim]. Auch als Free-Tool.
- **Status LinkBoss:** Released
- **Status Linkwise:** **MISSING**
- **Priorität V2:** **HIGH** — konzeptionell sauber, SEO-relevant, einfach erklärbar
- **Empfohlene Linkwise-Umsetzung:** Index-Query über alle `anchor_text` Werte, Group-By + Count(distinct target). Liste mit Conflict-Anchors + Bulk-Resolve-Flow („rename N anchors to ...").
- **Komplexität:** Niedrig-Mittel (1 Woche)

### 4.04 Duplicate-Link-Detection
- **Description:** „track duplicate link waste" [LB-claim]
- **Status LinkBoss:** Released
- **Status Linkwise:** **TEILWEISE** — outboundLinkOccurrences existiert seit `f57bc85` (Inbound-Count-Drift-Fix). Report nicht surfaced.
- **Priorität V2:** MEDIUM
- **Empfohlene Linkwise-Umsetzung:** Report-Tab: pro Entry Liste der Targets die mehrfach gelinkt sind. Daten existieren bereits.
- **Komplexität:** Niedrig (3 Tage)

### 4.05 Link-Flow-Score
- **Description:** „exclusive Link Flow Score system" [LB-claim] — single-number health metric pro Site/Page
- **Status LinkBoss:** Released, vermarktet als USP
- **Status Linkwise:** **MISSING**
- **Priorität V2:** MEDIUM (marketing-value > engineering-value; Definition wäre proprietär)
- **Empfohlene Linkwise-Umsetzung:** Gewichteter Score: `(linked_entries / total_entries) * 0.4 + (avg_inbound_per_entry / target) * 0.3 + (1 - orphan_ratio) * 0.3`. Pro-Site Dashboard-Tile. Kein deep science; nice-to-have für Marketing.
- **Komplexität:** Niedrig (3 Tage)

### 4.06 Broken-Link-Checker
- **Description:** „Check internal & external broken link" [hard-fact, Roadmap-pending bei LinkBoss]
- **Status LinkBoss:** **Pending** (nicht released!)
- **Status Linkwise:** **DONE** (Broken-Links-Report existiert)
- **Priorität:** — Linkwise ist hier voraus.

### 4.07 Site Visualizer / Interactive Network Graph
- siehe 2.03

---

## 5. ANCHOR-TEXT-MANAGEMENT

### 5.01 Anchor-Distribution-Visualizer
- **Description:** „visualize anchor distribution, eliminate cannibalization through NLP suggestions" [LB-claim]
- **Status LinkBoss:** Released
- **Status Linkwise:** **MISSING**
- **Priorität V2:** MEDIUM
- **Empfohlene Linkwise-Umsetzung:** Pro Target-Entry: Pie/Bar von Anchor-Texten die zu diesem Target zeigen. Daten in Index. UI ist Hauptarbeit.
- **Komplexität:** Niedrig-Mittel (1 Woche)

### 5.02 Anchor-Edit-Before-Insert
- **Description:** Anchor-Texte vor Insert editieren
- **Status LinkBoss:** Released
- **Status Linkwise:** **NOT STARTED** (siehe LINK-WHISPER-FEATURE-INVENTORY.md §1.04 — bereits dort gelistet)
- **Priorität V2:** HIGH (cross-source-confirmation: zwei Konkurrenten haben das, wir nicht)

### 5.03 Bulk Anchor Renaming
- **Description:** Implizit in „Anchor Manager" — Sammel-Rename mehrerer Anchors
- **Status LinkBoss:** Released (Teil von Anchor Manager)
- **Status Linkwise:** **MISSING**
- **Priorität V2:** MEDIUM
- **Komplexität:** Mittel (1 Woche) — Bulk-Pfad existiert, neue Command-Klasse

---

## 6. INTEGRATIONS

### 6.01 Google Search Console Anbindung
- **Description:** „Track where your articles show up on Google" [hard-fact, Roadmap-completed]. „GSC-powered rank tracking" [LB-claim]. In LinkBoss vs LinkWhisper-Blog als 1 von 6 fehlenden LinkWhisper-Features genannt: „GSC-Powered Analytics (full depth)" — also nicht nur Rank-Display, sondern Linking-Entscheidungen mit GSC-Daten füttern.
- **Status LinkBoss:** Released, prominent vermarktet
- **Status Linkwise:** **MISSING**
- **Priorität V2:** **HIGH** ← upgraded von MEDIUM. Vorherige Begründung („Statamic-User sind weniger SEO-Power-User") war Spekulation, nicht belegt — und widersprüchlich: Aerni's `advanced-seo`-Addon ist im Statamic-Markt beliebt, Statamic-Wahl korreliert oft mit SEO-Bewusstsein. Verstößt gegen `feedback_hard_facts_no_fantasy`.
- **Konkrete Linkwise-Mehrwerte (über LinkBoss hinaus möglich):**
  1. **Striking-Distance-Report** — Entries auf Google-Position 11-20 surfacen + auto-suggest welche internal Links sie auf Page 1 boosten könnten. Das ist *der* SEO-Power-Workflow, größter Conversion-Lever.
  2. **Rank-aware Anchor-Suggestion** — Top-Query aus GSC für Target-Entry als Anchor-Vorschlag (statt nur TF-IDF-Anchor). Direkter Ranking-Boost-Pfad: linke mit dem Wort, für das die Page schon halb-rankt.
  3. **Underperformer-Identifikation** — High-Impressions + Low-CTR + Low-Position-Entries als Priority-Targets für internal Linking.
  4. **Activity-Log-Ranking-Delta** — Pre/Post-Link-Insertion-Position pro Target tracken. Zeigt empirisch ob Linkwise-Vorschläge tatsächlich wirken. **Das hat LinkBoss nicht** — direkter Differentiator.
- **Empfohlene Linkwise-Umsetzung:** Laravel-Google-API-Client + OAuth2-Flow → verschlüsselt in Statamic-Settings. Daily-Cron pull Top-Queries + Position pro Entry-URL. Speichern in eigener Index-Tabelle. Pro Entry: GSC-Card im CP. Plus neue Reports (Striking Distance). Quota = 1200 queries/Min, kein Problem für realistic Site-Größen.
- **Komplexität:** **Mittel (1.5-2 Wochen)** ← korrigiert von Hoch (3-4 Wochen). OAuth-Flow ist via Google-API-PHP-Client + Laravel-Socialite-Adapter ein paar Stunden. Daily-Pull-Cron + Storage = 2-3 Tage. UI-Cards + Striking-Distance-Report = ~1 Woche. Quota-Management überschätzt.
- **Marketing-Punkt:** „Connect Google Search Console" auf der Listing-Page = sofortiges Premium-Signal, screenshot-tauglich. Selbstständiger Verkaufsgrund unabhängig vom AI-Pivot.

### 6.02 External-Link-Suggestion (high-DR sources)
- **Description:** „Suggests only high DR authoritative external links to build relevancy" [hard-fact, Roadmap-pending bei LinkBoss]
- **Status LinkBoss:** **Pending** (nicht released)
- **Status Linkwise:** **MISSING**
- **Priorität V2:** LOW (DR-Daten = Drittanbieter-Datenquelle = Pricing-Problem)

### 6.03 Multi-Site-Dashboard
- **Description:** „Manage all client sites in one place" — outperforms Link Whisper laut skywork.ai-Review
- **Status LinkBoss:** Released
- **Status Linkwise:** **N/A — Architektur-Mismatch.** Linkwise ist self-hosted per Statamic-Installation, 99€ one-time. Multi-Site-Dashboard erfordert SaaS-Backend. **Strategisch: NICHT bauen** (würde Geschäftsmodell brechen).
- **Empfehlung:** Marketing-Angle drehen → „Each Statamic install owns its data. No vendor lock-in."

---

## 7. WORKFLOWS / PRODUCTIVITY

### 7.01 Search-and-Replace (safe global)
- **Description:** „Safe search & replace feature" [hard-fact, Roadmap-in-progress bei LinkBoss]
- **Status LinkBoss:** In Progress (nicht released)
- **Status Linkwise:** **DONE** (URL Changer)
- **Priorität:** — Linkwise voraus

### 7.02 Team Members / Multi-User
- **Description:** „Adding team members to manage the projects" [hard-fact, Roadmap-in-progress bei LinkBoss]
- **Status LinkBoss:** In Progress
- **Status Linkwise:** **DONE indirekt** — Statamic-Permission-System handhabt Multi-User nativ.

### 7.03 Auto-Link selected keywords → URL
- **Description:** „Auto link selected keywords to a URL" [hard-fact, Roadmap-in-progress bei LinkBoss]
- **Status LinkBoss:** In Progress (nicht released!)
- **Status Linkwise:** **DONE** (AutoLink-Rules existieren seit V1.0)
- **Priorität:** — Linkwise **deutlich voraus**. Marketing-Punkt.

---

## 8. PLATFORM-SPEZIFISCH (nicht applicable für Statamic/Linkwise)

| Feature | Status bei LinkBoss | Linkwise-Equivalent |
|---|---|---|
| WordPress-Plugin-Integration | Full Auto | N/A — Statamic-Addon |
| Shopify-Integration | Full Auto | N/A |
| Page-Builder-Support (Elementor/Divi/Bricks/Oxygen/Thrive/Beaver/Gutenberg/ACF) | Released | N/A — Statamic-Bard ist single canonical editor |
| „Any CMS beta" (suggest+copy/paste) | Released | N/A — Linkwise = Statamic-tief |

**Strategisches Argument:** LinkBoss verbringt enormen Engineering-Aufwand auf Multi-Editor-Kompatibilität. Linkwise tut das nicht müssen, weil Statamic einheitlich ist. Marketing-Differentiator: „One editor, one source of truth, zero plugin conflicts."

---

## 9. PRICING / ÖKONOMIE (kein Feature, aber strategisch)

| Plan | Preis | Credits/yr | Linkwise-Vergleich |
|---|---|---|---|
| Launch | $99/yr | 2,400 | 99€ one-time |
| Scale | $333/yr | 12,000 | — |
| Agency | $891/yr | 30,000 | — |
| Dominator | $4,941/yr | 180,000 | — |

[hard-fact, multiple sources]

**Quellen-Drift Free-Tier-Credits:** linkboss.io Homepage sagt „50 free credits on signup"; skywork.ai-Review sagt „Only 100 welcome credits in the trial". Eine von beiden Quellen ist veraltet — vor Marketing-Argument mit konkreter Zahl: einmal manuell verifizieren.

- LinkBoss: Recurring + per-link-credit. Cancel → laut Drittquellen JavaScript-Links verschwinden (LinkBoss bestreitet das marketing-seitig).
- Linkwise: 99€ Marketplace-Einmalkauf. Links sind Bard-Mark-Mutationen, cancel-resistant strukturell.
- **Differentiator:** „Cancel-proof links. Your content stays yours." — testbare Aussage, harter Vorteil.

---

## 10. SPRACHEN / I18N

- **LinkBoss:** „31 languages including English, Spanish, German, Dutch, French, Portuguese, and others" [hard-fact, Roadmap-completed]
- **Linkwise:** 12 Sprachen Coordinator-Liste (`feedback_known_fragility_coordinators.md`), V1.2 multilang released
- **Gap:** 19 Sprachen Differenz, aber: Linkwise's Stemming + Coordinator-Liste sind tiefer/strukturierter als ein Stopword-Switch. Drittquellen-Beleg fehlt was LinkBoss's „31 languages" inhaltlich bedeutet.
- **Priorität V2:** LOW — auf Customer-Demand erweitern, nicht spekulativ.

---

## 11. PRIORISIERUNGS-MATRIX V2

| # | Feature | Differentiator-Wert | Engineering-Cost | Verdict |
|---|---|---|---|---|
| 1.01 | Embeddings als Re-Ranker | **HIGH** | Hoch | **DO** — die strategische Schlacht |
| 2.01 | Silo-Builder | **HIGH** | Mittel | **DO** — Markt zeigt: SEO-Konsulenten kaufen das |
| 2.03 | Visual Network Graph | **HIGH (marketing)** | Mittel | **DO** — Screenshot-Gold für Listing |
| 4.03 | Cannibalization-Report | HIGH | Niedrig-Mittel | **DO** — Quick-Win |
| 5.02 | Anchor-Edit-Before-Insert | HIGH | Niedrig | **DO** — bereits in LinkWhisper-Inventory, jetzt cross-confirmed |
| 1.02 | Smart Link Generator (LLM-Paragraph) | HIGH | Mittel | **DO nach 1.01** — gleiche AI-Infra |
| 3.01 | Bulk-1000-in-1-Click | HIGH (marketing) | Mittel | **DO** — Backend trivial, UI investiert |
| 4.05 | Link-Flow-Score | Mittel | Niedrig | **DO** — Marketing-cheap |
| 4.04 | Duplicate-Link-Report | Mittel | Niedrig | **DO** — Daten da |
| 4.02 | Dead-End-Report | Mittel | Niedrig | **DO** — Daten da |
| 6.01 | GSC-Anbindung + Striking-Distance + Rank-aware-Anchors | **HIGH** | Mittel (1.5-2 Wochen) | **DO** — eigener Verkaufsgrund, Premium-Signal, Activity-Log-Ranking-Delta wäre Über-LinkBoss-Differentiator |
| 5.01 | Anchor-Distribution-Viz | Mittel | Niedrig-Mittel | DO später |
| 3.03 | Batch-Undo | Mittel | Niedrig | DO später |
| 1.03 | NLP-Anchor (POS-Tagging) | Mittel | Mittel-Hoch | DEFER — Stopwords-Track erstmal |
| 6.02 | External-Link-Suggestions | Niedrig | Hoch (DR-Daten) | DEFER |
| 6.03 | Multi-Site-Dashboard | Negativ | Hoch | **NICHT BAUEN** — bricht Geschäftsmodell |
| 3.04 | Bulk-Link-Remover | Niedrig | Niedrig | DEFER — destructive UX-Risk |

---

## 12. STRATEGISCHE OFFENE FRAGEN (für Entscheidung)

1. **Embedding-Backend:** OpenAI-API (BYO-Key) vs. lokales Modell (ollama) vs. eigener Cloud-Service?
   - BYO-Key: Pricing bleibt one-time, User trägt API-Kosten. Kompatibel mit V1-Modell.
   - Lokal: Kein Setup, aber Statamic-Hoster sind oft Shared-Hosting → ollama unrealistisch.
   - Eigener Service: Bricht 99€-one-time-Modell → großer Schritt.
   - **Empfehlung (advisor consultation pending):** BYO-Key mit OpenAI ODER Anthropic — User-Onboarding ist eine Settings-Page.

2. **AI-Versprechen-Niveau:** Wie aggressiv vermarkten?
   - LinkBoss claimt „99.7% semantic accuracy" → Drittquellen liefern „repetitive suggestions, irrelevant occasional".
   - Linkwise sollte **konservativer** claimen: „AI-assisted relevance scoring on top of deterministic candidate filtering" statt „AI link suggestions". `feedback_hard_facts_no_fantasy.md` greift.

3. **Silo-Builder-Heuristik:** Topic-Cluster-Bestimmung wie?
   - Embeddings-Clustering (k-means auf entry-embeddings) — saubere Lösung.
   - Tag/Category-basiert (Statamic-Taxonomies) — trivial, weniger sophisticated.
   - Empfehlung: erst Taxonomies (V2-early), dann Embedding-Cluster (V2-late).

4. **Visual Graph: d3 vs cytoscape?**
   - cytoscape.js ist purpose-built für Network-Graphs, weniger Tuning.
   - d3 ist flexibler aber Boilerplate-heavy.
   - Empfehlung: cytoscape.

---

## 13. WAS LINKBOSS *NICHT* HAT (Linkwise-Differentiatoren erhalten)

- **Working Broken-Link-Checker** — bei LinkBoss roadmap-pending, bei Linkwise released
- **Working Search-and-Replace** — bei LinkBoss in-progress, bei Linkwise released (URL Changer)
- **Working Auto-Link-Rules (keyword → URL)** — bei LinkBoss in-progress, bei Linkwise V1-Core
- **Bard-Mark-Native Links** (cancel-proof, no JS-injection-on-render)
- **Multilang per-Entry-Locale-Stemmer** (V1.2) — LinkBoss listet „31 languages" ohne Drittquellen-Detail
- **Statamic-native CP-Integration** (Inertia + Vue, kein iframe/external-SaaS)
- **One-time-licensing-Modell** — kein Recurring, keine Credit-Anxiety
- **Activity-Log mit batch_id + Undo** (Sister-Audit-validated)

**Marketing-Punkte für Listing/Docs:** „Linkwise is what LinkBoss's roadmap *promises* — already shipped, no credits, no JavaScript magic."

---

## 14. SOURCES

| Source | Type | Used for |
|---|---|---|
| linkboss.io/ | LinkBoss marketing | Feature claims (labeled [LB-claim]) |
| linkboss.io/knowledgebase/linkboss-use-cases/ | LinkBoss marketing | Use cases, modules |
| linkboss.io/bulk-auto-interlinking-tool/ | LinkBoss marketing | Bulk feature claims |
| linkboss.io/roadmap/ | LinkBoss product | Pending/in-progress/completed status (hard-fact) |
| linkboss.io/free-tools/ | LinkBoss product | Free-tool list (hard-fact) |
| linkboss.io/cms/ | LinkBoss product | Supported CMS list (hard-fact) |
| linkboss.io/blog/linkboss-vs-linkwhisper/ | LinkBoss marketing | Self-comparison (clearly labeled bias) |
| linkboss.io/blog/best-internal-linking-tools/ | LinkBoss marketing | Self-comparison vs many tools |
| facileway.com/linkboss-pricing/ | 3rd-party aggregator | Pricing tiers (hard-fact) |
| skywork.ai/...linkboss-ai-interlinking-review | 3rd-party review | Pros/cons, comparison |
| bymilliepham.com/linkboss-review | 3rd-party review | Pros/cons, AI-quality |
| producthunt.com/products/linkboss-...reviews | 3rd-party user reviews | 4.9/5 rating, user quotes |
| Web search aggregate | 3rd-party | „Irrelevant suggestions" + „JS-link-permanence-concern" claims |

**Quellen die NICHT durchgekommen sind:**
- ciroapp.com (429 rate-limit)
- g2.com/products/linkboss (403 forbidden)
- trustpilot.com/review/linkboss.io (403 forbidden)
- kawkabnadim.com/linkboss-review (ECONNREFUSED)

→ Bei kritischen Folge-Entscheidungen (besonders 1.01 AI-Pivot) sollten G2 + Trustpilot manuell vom User geprüft werden, bevor 4+ Wochen Engineering investiert werden.
