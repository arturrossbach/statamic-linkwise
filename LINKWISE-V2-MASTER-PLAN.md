# Linkwise V2 Master-Plan

**Stand:** 2026-05-27
**Quellen-Disziplin:** Jede Feature-Aussage referenziert primary source. Linkwise-Defensive-Karten + LinkBoss/LinkWhisper-Detail → siehe [`LINKBOSS-FEATURE-INVENTORY.md`](LINKBOSS-FEATURE-INVENTORY.md) + [`LINK-WHISPER-FEATURE-INVENTORY.md`](LINK-WHISPER-FEATURE-INVENTORY.md). V1.x-Polish-Findings → [`V1X-POLISH-AUDIT.md`](V1X-POLISH-AUDIT.md). Dieser Plan **referenziert**, dupliziert nicht.

---

## §0. Executive Verdict

- **Sprint-0 V1.x-Polish kommt zuerst** (~7-10 Tage, 47 Findings im Polish-Audit). Erst nach User-Smoke-Approval auf Polish-Output startet V2.0.
- **V2 ist AI-Pivot via 3-stage retrieval + hard gates**, nicht Embedding-Replacement von TF-IDF. Architektur in §2.
- **Produkt-Prinzip: „Lieber Schweigen als Quatsch"** — Confidence-Floor als hard gate, „keine Suggestion" ist valide Antwort.
- **Verteidigte Differentiatoren:** Bard-Mark-native Links (cancel-proof vs. LinkBoss JS-Injection), One-time-Lizenz, Statamic-CP-native, Working-Features die bei LinkBoss noch roadmap-pending sind (Broken-Link, URL-Changer, Auto-Link-Rules).
- **Realistischer V2.0-Start:** Mid-Juni 2026. V2.0 Release-Kandidat: Anfang August.

---

## §1. Priorisierungs-Matrix

Spalten: Feature · Label · Primary Source · Engineering-Wochen · Test-Wochen

Aufwand-Format: `ENG / TEST` (Test inkl. Pin-Tests + Audit + User-Smoke-Pflicht aus `feedback_user_smoke_always_finds_bugs`).

### MUST (empirisch belegt als ranking-relevant)

| Feature | Quelle | ENG / TEST |
|---|---|---|
| Cannibalization-Detection (cross-target same-anchor) | Cyrus Shepard 23M Internal-Links Study via Zyppy | 0.5 / 0.5 |
| Anchor-Text-Diversity-Tooling | Cyrus Shepard: „variety of anchor text equals better rankings" | 0.5 / 0.5 |
| Internal-Link-Density-Report (Entries <40 inbound surfaced) | Zyppy: pages mit 40+ inbound = 4x traffic | 0.5 / 0.5 |

### SHOULD (Industry-Standard, dünneres Studien-Beleg)

| Feature | Quelle | ENG / TEST |
|---|---|---|
| 3-Stage AI-Suggestion-Engine (Embeddings + Reranker) | MTEB 2026 + Pinecone 48% retrieval-quality-lift + RAG-Forschung | 4 / 2 |
| GSC-Anbindung (Striking-Distance + Rank-aware-Anchor) | March-2024-Core-Update -30-60% bei poor internal arch | 1.5 / 0.5 |
| Smart-Paragraph-Generator (LLM für Orphan-Targets) | LinkBoss-Reviewer-Feedback (Product Hunt 4.9 + skywork.ai), kein Studien-Beleg | 1 / 0.5 |
| Topic-Cluster-Builder mit Flex-Linking | Ahrefs+Semrush 2026: „organize like silos, link like clusters" | 1.5 / 1 |

### NICE (legitim, Re-Bewertung vor Bau erforderlich)

| Feature | Quelle | Anmerkung |
|---|---|---|
| Anchor-Distribution-Visualizer | Cross-LinkBoss-Confirm | Passive awareness, nicht aktiv-fixend |
| Dead-End-Report | Standard SEO-Audit-Tooling | Daten teilweise da |
| Anchor-Edit-Before-Insert | LinkWhisper+LinkBoss beide haben's | Workflow-Polish |

**→ NICE-Items landen NICHT in V2.x.** Explizit „V3+ TBD".

### GARBAGE / Augenwischerei

| Feature | Warum Garbage |
|---|---|
| Visual Network Graph | User-Pushback („Spielerei"). Kein Studien-Beleg dass Graph-Anschauen Probleme fixt. Real-Workflow: Liste+Filter+Action |
| Bulk-1000-in-1-Click | Direkt anti-evidenz: Cyrus Shepard 23M-Study sagt Anchor-Diversity matters → 1000-in-1-click ist strukturell uniform-anchor-prone |
| Silo-Builder mit 4-5 Presets | Ahrefs+Semrush 2026: „strict siloing outdated". Was bleibt = Topic-Cluster (siehe SHOULD), nicht 4-Preset-Schwurbel |
| „Boss Mode" | Marketing-Sprache, kein klares Feature |
| Multi-Site-Dashboard | Bricht 99€-one-time-Lizenz-Modell strukturell |
| Link-Flow-Score (composite) | Marketing-cheap aber substanzlos — kein Studien-Beleg, was-misst-er bleibt opak |

---

## §2. AI-Architektur

### §2.1 5-Stage-Pipeline

```
SuggestionPipeline(sentence, target_pages)
  ├── Stage 0 — Title/H1-Token-Gate (HARD)
  │     target.indexed_tokens = NormalizeTokens({target.title, target.h1, target.top-3-frequency-terms})
  │     sentence_tokens ∩ target.indexed_tokens ≠ ∅
  │     ELSE: drop candidate (no embedding/rerank wasted)
  │
  ├── Stage 1 — TF-IDF (existing)
  │     top-200 candidates
  │
  ├── Stage 2 — Dense Embeddings (NEW)
  │     OpenAI text-embedding-3-small (1536 dims)
  │     Cosine vs. stored target-page-summary embeddings
  │     narrow to top-50
  │
  ├── Stage 3 — Cross-Encoder Rerank (NEW)
  │     Cohere rerank-3.5 (default) OR BGE-reranker-v2-m3 (opt-in lokal)
  │     top-10 with reranker-confidence-score
  │
  ├── Stage 4 — Anchor-Vocabulary-Constraint (NEW, HARD)
  │     anchor MUST be NP-substring from target.{title, h1, indexed_tokens}
  │     ELSE: drop suggestion (no valid anchor extractable)
  │
  └── Stage 5 — Confidence-Floor (NEW, HARD)
        if reranker_score < threshold[target]: drop
        no suggestion is valid output — „Schweigen statt Quatsch"
```

**Houthi-Sentence-Klasse — ehrliche Coverage-Schätzung:**
- Stage 0 Title/H1-Token-Gate erwischt **schätzungsweise ~80%** der V1-AI-Test Müll-Anchors (NER-Recherche-Agent-Schätzung 2026-05-26, **nicht gemessen**).
- Vollständige Lösung erfordert NER. PHP-natives NER ist auf Statamic-Shared-Hosting nicht deployable (MITIE/Stanford/Python-Sidecar disqualifiziert).
- **V2.x-Option (opt-in):** Cloud-NER via OpenAI gpt-4o-mini structured-output (~$0.13/1k Sentences). Erst aktivieren wenn V2.0 Customer-Smoke zeigt dass die 20% Lücke wirklich schmerzt.

### §2.2 Vendor-Default

**Hybrid: OpenAI embed + Cohere rerank.**
- OpenAI `text-embedding-3-small`: $0.02/1M tokens, 1536 dim, MTEB ~62. Bekannt, stabil, billig.
- Cohere `rerank-v3.5`: ~$2 / 1k search units. Cross-Encoder, 95% LLM-accuracy bei 3× speed (ZeroEntropy-Quelle).
- Swap-Interface erlaubt Voyage/BGE/Anthropic-Wechsel, aber EIN Default. Architektur erlaubt swap, Default ist fest.

**BYO-API-Key:** User trägt API-Kosten. Linkwise bleibt 99€-one-time. Keine Recurring-Backend-Last bei uns.

### §2.3 Storage

**MySQL/SQLite LONGBLOB + brute-force PHP-Cosine.**
- Embedding: 1536 float32 = 6KB pro Entry
- 2000 Entries × 6KB = 12MB storage — trivial
- Cosine-Loop in PHP: < 100ms für 2000 Entries (**Schätzung, nicht gemessen** — Sprint-V2.0-Akzeptanz-Kriterium: realer Smoke-Test mit 5k-Entry-Site. Falls > 500ms → Pivot zu `centamiv/vektor` HNSW oder pgvector-Required-Path. Architektur-Risiko-Vorab-Diskussion in §9.7)
- Statamic-Realität: SQLite seit v5/6 Default, MySQL via Eloquent-Driver. Both ok mit LONGBLOB.
- **NICHT Stache:** Stache ist ephemeral, embeddings rebuilden wäre $$-Verschwendung
- pgvector als opt-in-Path für Postgres-User dokumentieren, nicht default

### §2.4 Cost-Estimate (Customer, typische Statamic-Site 500 Entries)

| Operation | Cost |
|---|---|
| Initial Embedding (500 × 500 tokens × $0.02/1M) | ~$0.005 one-shot |
| Per-Suggestion-Run (embed query + rerank 50 candidates) | ~$0.002 |
| Monthly @ 100 Suggestions/Tag | ~$6/mo |
| Initial Cold-Start UX | Bootstrap-Job mit Progress-Bar pro Entry |
| Refresh | EntrySaved-Event + nightly-cron safety-net |

### §2.5 Negative-Mining-Loop (Tag-1-Feature)

User-Reject pro Suggestion wird gelogged. Pro Target-Page nach N Rejects (Default N=5) wird Threshold[target] um Step ε hochgezogen. Cold-Start-Default ist conservative (z.B. reranker_score ≥ 0.5). Calibration läuft automatisch in der Activity-Log-Pipeline. **Built-in von Tag 1, nicht „later".**

---

## §3. MUST-Features (Detail)

### §3.1 Cannibalization-Detection
- **Quelle:** Cyrus Shepard / Zyppy 23M-Internal-Links-Study: gleiche Anchor → mehrere Targets = ranking-confusion
- **Linkwise-Status:** MISSING
- **Skizze:** Query auf Index `anchor_text` GROUP BY + COUNT(DISTINCT target_id) > 1. Neuer Report-Tab im CP. Bulk-Resolve-Flow: rename Anchor in N Source-Entries via existing URL-Changer-Pattern.
- **ENG/TEST:** 0.5 / 0.5

### §3.2 Anchor-Diversity-Tooling
- **Quelle:** Cyrus Shepard: „90%+ same-anchor = over-optimization risk"
- **Linkwise-Status:** MISSING
- **Skizze:** Pro Target-Entry: anchor-distribution-Tabelle. Warn wenn >90% Anchors identisch. Suggestion-Pipeline schlägt diverse Synonyme aus Target.{title, h1, indexed_tokens} vor.
- **ENG/TEST:** 0.5 / 0.5

### §3.3 Internal-Link-Density-Report
- **Quelle:** Zyppy: pages mit 40+ inbound = 4x organic traffic
- **Linkwise-Status:** Daten teilweise da (inboundLinkOccurrences seit `f57bc85`), Report-UI fehlt
- **Skizze:** Neuer Report-Tab „Under-linked Entries" — sortiert nach inbound_count ASC, mit auto-suggest-buttons pro Zeile zum Erhöhen.
- **ENG/TEST:** 0.5 / 0.5

---

## §4. SHOULD-Features (Detail)

### §4.1 3-Stage AI-Suggestion-Engine
- **Quelle:** §2 oben — MTEB 2026 + Pinecone-48%-lift + Wikification-Forschung. RAG-Hallucination-Reduction-Quellen melden 70-80% fewer hallucinations bei dense+rerank-Pipelines.
- **Linkwise-Status:** TF-IDF aktuell, kein Embedding-Layer
- **Skizze:** Komplette Architektur in §2. Hybrid TF-IDF (existing) → Embeddings (new) → Cross-Encoder-Rerank (new) → Hard-Gates (Stage 0 + 4 + 5).
- **ENG/TEST:** 4 / 2 (incl. cold-start UX, negative-mining-loop, threshold-calibration-bootstrap)

### §4.2 GSC-Anbindung
- **Quelle:** March-2024 Core-Update: -30-60% traffic für poor internal arch. GSC-Daten als Rank-Tracker + Striking-Distance-Workflow.
- **Linkwise-Status:** MISSING
- **Skizze:** Laravel-Google-API + OAuth2-Flow. Daily-Cron pull Top-Queries + Position pro Entry. **Mehrwerte (siehe `LINKBOSS-FEATURE-INVENTORY.md` §6.01):** Striking-Distance (Pos 11-20 surfacen) + Rank-aware-Anchor-Suggestion + Ranking-Delta-Tracking (Über-LinkBoss-Differentiator).
- **Honest Caveat:** SHOULD nicht MUST. Statamic-Sites mit <1k Impressions/Tag haben sparse GSC-Daten und der Workflow lohnt sich nicht. Setup-Friction (OAuth) ist Hürde.
- **ENG/TEST:** 1.5 / 0.5

### §4.3 Smart-Paragraph-Generator
- **Quelle:** LinkBoss Product Hunt 4.9 + skywork.ai-Review nennen das positiv. **Kein Studien-Beleg.** Kommerziell tragfähig.
- **Linkwise-Status:** MISSING
- **Skizze:** Für Orphan-Targets: LLM-Call (gpt-4o-mini, ~$0.001/paragraph) der einen 2-3-Satz-Kontext-Paragraph generiert + via Bard-Mutator in Source-Entry einfügt. Klare Edit-Preview vor Insert (kein silent-write). Aus `feedback_no_silent_overwrite`: nur in NEUE Paragraphen einfügen, nie vorhandene mutieren.
- **ENG/TEST:** 1 / 0.5

### §4.4 Topic-Cluster-Builder
- **Quelle:** Ahrefs+Semrush 2026-Consensus: „topic clusters > strict silos"; SearchEngineLand: „organize like silos, link like clusters"
- **Linkwise-Status:** MISSING (kein Silo + kein Cluster aktuell)
- **Skizze:** Pillar-Entry markieren → Linkwise findet top-N thematisch-verwandte Entries via Embedding-Cluster (Stage-2-Reuse). Hub-and-Spoke-Bulk-Link-Vorschlag mit Per-Item-Preview (kein 1000-in-1-Click). **Keine starren Presets** (kein Reverse/Serial/Priority/Hybrid-Circle — das ist GARBAGE per Ahrefs/Semrush-Position).
- **ENG/TEST:** 1.5 / 1

---

## §5. NICE-Features

Bewusst dünn gehalten. **Re-Bewertung vor Bau Pflicht.**

- **Anchor-Distribution-Visualizer:** Passive awareness. Cyrus-Shepard-Study sagt „diversity matters", aber die *Erkenntnis* allein fixt nichts. Wenn V2.x-Customer-Feedback es als Bedarf nennt → V3.
- **Dead-End-Report:** Daten partiell da. Quick-Win wenn jemand danach fragt.
- **Anchor-Edit-Before-Insert:** Cross-Source-Confirmed (LinkWhisper + LinkBoss). Quick-Win wenn Workflow-Polish-Sprint kommt.

**→ Alle drei landen V3+ TBD, nicht in V2.x.**

---

## §6. GARBAGE / Augenwischerei (mit Begründung)

| Feature | Begründung Garbage |
|---|---|
| Visual Network Graph | User-Pushback 2026-05-27 („Spielerei"). Kein Real-Workflow-Beleg dass Graph-Visualisierung Internal-Linking-Probleme löst. Liste+Filter+Action gewinnt bei Power-Usern jeden Test |
| Bulk-1000-in-1-Click | Anti-Evidenz: Cyrus Shepard 23M-Study → Anchor-Diversity matters. 1000-in-1-Click ist strukturell uniform-anchor-prone. Pro: Marketing-Punkt. Contra: SEO-Schaden für Customer. Linkwise verkauft sich nicht über „we hurt your rankings prettily" |
| Silo-Builder mit 4-5 Presets | Ahrefs+Semrush+SearchEngineLand 2026: strict siloing outdated. Linkwise baut Topic-Cluster (siehe SHOULD §4.4), nicht Preset-Topologien |
| „Boss Mode" | LinkBoss-Marketing-Wort. Keine extrahierbare Feature-Definition. Garbage by analysis |
| Multi-Site-Dashboard | Bricht 99€-one-time-Lizenz-Modell. Erfordert SaaS-Backend. Strategischer Suizid |
| Link-Flow-Score (composite) | Marketing-cheap, kein Studien-Beleg, was-misst-er bleibt opak. „Composite metric for marketing screenshot" gehört nicht in ein Produkt das Vertrauen aufbauen will |

---

## §7. Sprint-Reihenfolge

### Sprint 0 — V1.x-Polish (~7-10 Tage)
Detail-Liste in [`V1X-POLISH-AUDIT.md`](V1X-POLISH-AUDIT.md). 30 Code + 17 Dark-Mode Findings.
- **0.A** Stabilität (HIGH Code + HIGH Dark-Mode) — 2-3 Tage
- **0.B** Code-Quality (MEDIUM) — 4-5 Tage
- **0.C** Polish (LOW) — 1-2 Tage als V1.3.0-Release-Candidate
- **User-Smoke-Gate** vor V2-Start (per `feedback_user_smoke_always_finds_bugs`)

### Sprint V2.0 — „AI Core" (6 Wochen — ENG 4 / TEST 2)
- §4.1 3-Stage AI-Engine (komplett: Stage 0+1+2+3+4+5 + Negative-Mining-Loop)
- §3.1 Cannibalization-Detection (parallel als Quick-Win)
- **User-Smoke-Gate** auf 2-3 Test-Sites
- Release: V2.0.0 mit ehrlicher AI-Vermarktung („AI-assisted relevance scoring on top of deterministic candidate filtering" — kein „99.7% semantic accuracy"-Bullshit per `feedback_hard_facts_no_fantasy`)

### Sprint V2.1 — „MUST Complete" (2 Wochen — ENG 1 / TEST 1)
- §3.2 Anchor-Diversity-Tooling
- §3.3 Internal-Link-Density-Report
- Release: V2.1.0

### Sprint V2.2 — „SHOULD Power-Pack" (5 Wochen — ENG 3.5 / TEST 1.5)
- §4.2 GSC-Anbindung
- §4.3 Smart-Paragraph-Generator
- §4.4 Topic-Cluster-Builder
- Release: V2.2.0

### V3+ TBD
- NICE-Items (Anchor-Distribution-Viz, Dead-End-Report, Anchor-Edit-Before-Insert)
- Cloud-NER für Houthi-Klasse 100% (opt-in)
- pgvector-Adapter (für Postgres-Customer)
- Mehr Sprachen on Customer-Report

**Total V2.0-V2.2:** **9.5 Wochen ENG + 4.5 Wochen TEST = 14 fokus-Wochen.** Plus ~4 Wochen Smoke-Iterationen / Sister-Bug-Wellen (historisch jeder Sprint hat eine; siehe `feedback_user_smoke_always_finds_bugs`) = **~18 Wochen Kalenderzeit**.

**Sprint-0 Anmerkung:** Die 7-10-Tage-Schätzung aus [`V1X-POLISH-AUDIT.md`](V1X-POLISH-AUDIT.md) ist plausibel, aber Pin-Test-Investment für CR-H-2 / CR-M-9 / CR-M-12 / CR-H-5 ist Teil davon. Nicht reduzierbar auf reines „Code-Editing".

---

## §8. Architektur-Entscheidungen die Tag-1 stehen müssen

| Entscheidung | Default | Swap-fähig |
|---|---|---|
| Embedding-Vendor | OpenAI text-embedding-3-small | ja (Voyage/Cohere/BGE per Interface) |
| Reranker-Vendor | Cohere rerank-3.5 | ja (BGE-reranker-v2-m3 lokal opt-in für Datenschutz-User) |
| Storage | MySQL/SQLite LONGBLOB + PHP-Cosine | pgvector als V3-Opt-in |
| API-Key-Modell | BYO (User trägt Kosten) — **User-Entscheidung erforderlich, siehe §9.9** | NICHT Linkwise-Cloud-Service (bricht 99€-Modell) |
| Threshold-Default | reranker_score ≥ 0.5 | per-target calibration via negative-mining |
| NER | Title/H1-Token-Gate (Stage 0) | optional V2.x opt-in Cloud-NER |
| Cold-Start-UX | Bootstrap-Job mit Progress-Bar | non-blocking (User kann CP weiter nutzen) |

---

## §9. Risiken + Offene Fragen

1. **NER-Lücke ehrlich:** Stage-0-Title/H1-Token-Gate erwischt ~80% Houthi-Klasse (Schätzung NER-Agent, **nicht gemessen**). 20% Restrisiko. Mitigation: V2.0-User-Smoke-Phase mit explizitem „Houthi-Klasse-Hunt" — wenn Bug-Reports kommen, Cloud-NER aktivieren als V2.1.
2. **Cost-Drift:** OpenAI/Cohere Pricing kann sich ändern. Mitigation: Swap-Interface heißt User kann auf Open-Weight BGE wechseln wenn API zu teuer wird.
3. **Cold-Start-UX:** First-time-Customer mit 2000 Entries wartet ~30s auf Initial-Embedding. Mitigation: Background-Job + Progress-Bar + CP bleibt benutzbar.
4. **Per-Target-Threshold-Calibration cold-start:** Default 0.5 könnte zu strikt oder zu lax sein, je nach Embedding-Modell. Mitigation: Telemetrie über Reject-Rate; nach 100 Linkwise-Installations Threshold-Default justieren.
5. **„Schweigen statt Quatsch" Customer-Risiko:** Customer könnte sich beschweren „warum gibt's keine Suggestion?". Mitigation: UI zeigt explizit „No suggestions above confidence threshold" + Link zu Threshold-Setting.
6. **AI-Marketing-Niveau:** LinkBoss claimt „99.7% semantic accuracy". Linkwise sollte konservativer claimen. Per `feedback_hard_facts_no_fantasy` + `feedback_advisor_before_recommendations`: Marketing-Copy vor Release durch Advisor.
7. **Brute-force PHP-Cosine Performance:** <100ms bei 2000 Entries als Schätzung. Bei 10k+ Entries linear schlechter. Mitigation: Sprint V2.0 hat als Akzeptanz-Kriterium: gemessener Real-Flow-Smoke mit 5k-Entry-Site.
8. **GSC-Setup-Friction:** OAuth + Property-Selection + Permissions. Statamic-User Tech-Affinität gemischt. Mitigation: dokumentierter Setup-Guide, evtl. Video.
9. **BYO-API-Key als UX-Decision (User-Entscheidung erforderlich):** Marketplace-Customer könnten erwarten dass 99€-Addon „einfach funktioniert", nicht „besorg dir API-Keys". Alternativen: (a) BYO-Key (Default in §8) — User-Setup-Friction aber sauberer Geschäfts-Trennstrich, (b) Linkwise-Cloud-Service mit Subscription — bricht 99€-one-time-Modell, (c) Hybrid: 100 Linkwise-Cloud-Suggestions/Monat free + BYO-Key danach — Komplexität in Pricing-Tier-Architektur. **Entscheidung muss VOR Sprint-V2.0-Start fallen** weil sie UI-Flow + Onboarding-Wizard betrifft. Per `feedback_pricing_decision_v1` ist 99€-one-time bestätigt — daraus folgt (a) als logisch konsistent, aber UX-Risiko explizit.

---

## §10. Sources

**SEO-Empirie:**
- Cyrus Shepard / Zyppy: 23M Internal Links Study — pages mit 40+ inbound = 4x organic traffic, anchor-diversity > uniformity
- Ahrefs + Semrush 2026 Consensus: „topic clusters > strict silos"
- SearchEngineLand 2026: „organize like silos, link like clusters"
- Backlinko Internal-Links-Guide: keine fixed-count, diversity matters
- Semrush-Studie: 50% sites duplicate-content, 35% broken internal — „erodes signals"
- March-2024 Google Core Update: -30-60% traffic für poor internal architecture

**AI-Architektur:**
- MTEB Leaderboard April 2026 (awesomeagents.ai)
- ZeroEntropy Reranking-Guide 2026
- BGE-Reranker-v2-m3 GitHub (Apache 2.0, 100+ languages, 50-100ms GPU)
- Cohere rerank-v3.5 ($2/1k search units)
- Pinecone three-stage retrieval study (+48% retrieval quality)
- RAG-Hallucination-Reduction-Forschung (70-80% fewer hallucinations bei dense+rerank)
- Wikification academic-field (anchor-text-ambiguity = false-positive source)
- Sentence-Transformers Threshold-Kalibrierung Docs

**Statamic-Realität:**
- Statamic Stache Docs (ephemeral, nicht-authoritativ)
- Statamic Storing-Content-in-Database (SQLite Default seit v5/6)
- benbjurstrom.com/sqlite-vec-php (PHP-Binary-Problem)
- pgvector/pgvector-php (Postgres-only, gut aber restrictive)

**PHP-NER-Realität:**
- Packagist-Suche: kein production-grade PHP-NER 2026
- yooper/php-text-analysis (Stanford-JAR-required, shared-hosting-tot)
- OpenAI gpt-4o-mini Pricing: $0.15/1M input + $0.60/1M output
- Anthropic Claude Haiku 4.5 Pricing: $1.00 / $5.00 per 1M tokens
- Google Cloud Natural Language: $0.0010/Unit nach 5k Free-Tier

**Konkurrenz-Recherche:**
- [`LINKBOSS-FEATURE-INVENTORY.md`](LINKBOSS-FEATURE-INVENTORY.md) — verbatim Quotes + 3rd-party Triangulation
- [`LINK-WHISPER-FEATURE-INVENTORY.md`](LINK-WHISPER-FEATURE-INVENTORY.md) — bestehende Inventory
- 3rd-party LinkBoss-Reviews: Product Hunt 4.9★ (17 reviews), skywork.ai, bymilliepham.com
- Drittquellen-Befund: LinkBoss-JS-Injection ⇒ „links disappear on cancel" (direkter Widerspruch zu LinkBoss-Marketing „permanent forever")
