# Architecture Review — Klassen-Audit Subtitle-Smoke (2026-05-16)

Diese Datei dokumentiert den Audit-Pass für die drei Bug-Klassen, die aus
dem subtitle-Smoke-Bug abgeleitet wurden (PR `55b33b0`, Memory
`bug_classes_2026_05_16.md`).

Pro Klasse:
1. **Pattern** — die Wurzel-Form des Bugs.
2. **Audit-Methode** — welche grep-Vektoren wurden durchgegangen.
3. **Kandidaten geprüft** — jede Call-Site explizit klassifiziert.
4. **Befunde** — bestätigte Sister-Bugs vs. by-design vs. latent.
5. **Empfehlung** — Fix-Plan oder bewusste Schuld.

Findings werden in `architectural_health.md` als laufende Liste
gespiegelt; diese Datei ist der detaillierte Audit-Beleg.

---

## Klasse A — Indexer/Writer-Field-Symmetrie

### Pattern

Der Indexer (`EntryIndexer::indexEntry`) sammelt Anchor-Quellen aus
strikt breiter als der Writer (`BardLinkInserter::insertLinkIntoEntryWithHref`)
beschreiben darf. Resultat: Phantom-Suggestions deren Anker im
Index liegt aber nicht in einem writbaren Feld — Apply schlägt mit
"anchor not found in writable region" fehl.

Gefixt in `55b33b0`:
- `EntryIndexer.php:355-379` — Top-Level: nur `markdown`-Felder
  (text/textarea raus).
- `EntryIndexer.php:585-609` (`extractBardFromReplicator`) — innerhalb
  von Replicator-Sets: nur Bard-Fragmente, plain-String set-Felder raus.

### Audit-Methode

- Vergleich: jede Field-Type-Verzweigung im Indexer gegen die
  Field-Type-Verzweigung im Writer.
- Sekundärquellen: `TargetKeywordManager` (custom keywords),
  `KeywordExtractor` (TF-IDF), `outboundLinks`-Konsumenten.

### Kandidaten geprüft

| Stelle | Indexer-Verhalten | Writer-Verhalten | Befund |
|---|---|---|---|
| `bard`-Felder | gesammelt (`EntryIndexer:569`) | gewrapped (`BardLinkInserter:341`) | ✅ symmetrisch |
| `replicator` (Bard-Fragmente) | rekursiv gesammelt | rekursiv gewrapped | ✅ symmetrisch |
| `replicator` (plain-string sets) | nach Fix übersprungen | übersprungen (`ReplicatorLinkRouter:160-167`) | ✅ symmetrisch nach Fix |
| `markdown`-Felder (top-level) | gesammelt (`EntryIndexer:365`) | gewrapped (`BardLinkInserter:363`) | ✅ symmetrisch (beide skip `handle === 'title'`) |
| `text`/`textarea` | nach Fix übersprungen | übersprungen (impliziter else-Zweig) | ✅ symmetrisch nach Fix |
| Custom Keywords (`TargetKeywordManager`) | matched gegen `$sourceRecord->text` (`InboundEngine:201`) | dasselbe `$text` flow | ✅ symmetrisch (folgt automatisch der Top-Level-Symmetrie) |
| TF-IDF (`KeywordExtractor`) | Korpus aus `$record->text` | n/a (TF-IDF schreibt nicht) | ✅ kein Schreib-Pfad |

### Befunde

**Keine zusätzlichen Sister-Bugs.** Die zwei Manifestationen aus
`55b33b0` waren die vollständige Liste der Indexer-Writer-Field-Drifts
im aktuellen Stand.

**Latentes Risiko (dokumentiert, nicht gefixt):**

> **Markdown-Stripping-Asymmetrie** — `EntryIndexer:371` ersetzt
> `[#*_~`>]` durch `''` bevor `$text` an die Suggestion-Pipeline
> geht. Der Writer (`MarkdownLinkInserter:150-156`) sucht den Anker
> via `mb_strpos` im **Raw**-Markdown. Edge-Case: Anker spannt eine
> Bold/Italic-Grenze (z. B. `**foo** bar` → flat: `foo bar`). Token-
> Matcher findet `foo bar`, Writer findet im Raw nichts.
>
> **Heutiges Verhalten:** Phantom landet in `suggestFiltered`'s
> Dry-Run-Filter (`InboundEngine:158`) → wird stillschweigend
> rausgefiltert. User sieht nichts. Reines Compute-Waste, KEIN
> User-facing-Bug.
>
> **Empfehlung:** keine Aktion. Wenn das Pattern später mit messbarem
> Compute-Schaden in einem großen Korpus auftaucht, kann eine
> Indexer-Stripping-Spiegelung im Writer-Match nachgereicht werden
> — bis dahin nicht ohne empirischen Anlass refactoren
> ([[feedback_refactor_must_prove_value]]).

### Empfehlung

Klasse A in `architectural_health.md` als **abgeschlossen** markieren.
Ein **Audit-Check** (`linkwise:audit`) wäre Verschwendung — die zwei
Manifestationen waren empirisch entdeckt; ein präventiver Reflektions-
Vergleich von Indexer- vs. Writer-Field-Sets würde False-Positives für
jede legitime Asymmetrie erzeugen (z. B. der `markdown`-vs.-`text`-Skip
auf Top-Level ist legitim, kein Bug).

---

## Klasse B — Filter-Apply-Argument-Parität

### Pattern

Dry-Run-Filter (Validate-Pfad) und Real-Write (Apply-Pfad) rufen
denselben Inserter mit **unterschiedlichem Argument-Set**. Der
Filter akzeptiert mehr als der Real-Write — User sieht Suggestion,
klickt Apply, kriegt silent-Refusal mit `context_mismatch`.

Gefixt in `4e6573d`:
- `InboundEngine.php:158` — `$expectedSentenceContext` jetzt
  6. Argument an Dry-Run.

Out-of-scope geflaggt: `OutboundSuggestionGrouper.php:28`
(in `4e6573d` Commit-Message).

### Audit-Methode

`grep -rn "insertLinkIntoEntryWithHref\|canInsertLinkIntoEntry" src/`
— jede Call-Site klassifiziert nach (a) Filter vs. Apply, (b)
übergebene Argumente, (c) Symmetrie zur Partner-Stelle.

### Kandidaten geprüft

| Stelle | Rolle | Args | Partner-Apply | Symmetrie |
|---|---|---|---|---|
| `LinkInsertCommand:198` | **Apply** | 6 Args inkl. `sentence_context` | n/a (Apply self) | ✓ |
| `InboundEngine:158` (gefixt) | **Filter** | 6 Args nach Fix | `LinkInsertCommand:198` | ✅ |
| **`OutboundSuggestionGrouper:28`** | **Filter** | 5 Args, KEIN context | `LinkInsertCommand:198` | ❌ **Sister-Bug** |
| **`EntryIndexer:207`** (Phase 2) | **Filter** | 5 Args, KEIN context | `LinkInsertCommand:198` | ❌ **Sister-Bug** |
| `AutoLinkApplier:156-158` (preview) | **Filter** | 5 Args, KEIN context | `AutoLinkApplier:214-219` (apply) | ✅ by-design (AutoLink-Rule hat per Spec keinen `sentence_context`) |
| `AutoLinkApplier:214-219` (apply) | **Apply** | 4 Args (save defaults true) | n/a | by-design |
| `AutoLinkApplier:267` (`performInsert` seam) | **dispatch** | 6 Args, `expectedSentenceContext` als Param | n/a | beide Aufrufer (preview + apply) lassen `expectedSentenceContext` weg → identisch |
| `AutoLinkOnEntrySaveSubscriber:94` | **Apply** | 4 Args | n/a (kein Filter davor) | ✓ (kein Filter-Apply-Paar) |
| `EntryIndexer:207` siehe oben | | | | |
| `AuditCommand:753` | **Audit-Probe** | 5 Args | n/a (offline) | ✓ (kein User-Surface) |
| `AuditCommand:844` | **Audit-Probe** | `canInsert`, 3 Args | n/a | ✓ |
| `AuditCommand:2196` | **Audit-Probe** | 5 Args | n/a | ✓ |
| `InboundEngine:294` | **Presence-Check** | 3 Args (`anchorIsLinkedInEntry`) | n/a (synthetischer Marker, by-design) | ✓ |
| `BardLinkInserter:219` | internal recursion | n/a | n/a | ✓ |

### Befunde

**Zwei zusätzliche Sister-Bugs bestätigt:**

#### B-1: `OutboundSuggestionGrouper.php:28`

```php
return BardLinkInserter::insertLinkIntoEntryWithHref(
    $entryId, $s->anchorText, $href, false, false
    // ← fehlt: $s->sentenceContext
);
```

`Suggestion` trägt `sentenceContext` (`Suggestion::__construct`),
der Apply-Pfad (`LinkInsertCommand:198-211`) übergibt es als
`expectedSentenceContext`. Outbound-Modal zeigt heute Vorschläge
deren Anchor im Index existiert aber nicht im erwarteten Satz-
Kontext — Apply silent-failed mit `context_mismatch`.

**Impact:** identisch zum Inbound-Bug. User sieht Vorschlag im
Modal, klickt Apply, Apply rejected silent (kein UI-Feedback weil
der Outbound-Bulk-Pfad keinen per-item-Toast für `context_mismatch`
hat — bestenfalls landet's im "skipped"-Counter).

**Konsumenten:** `OutboundController` (Modal-API),
`EntryIndexer::enrichWithSuggestionCountsStreamed` über
`countGroups` (line 148, 258).

#### B-2: `EntryIndexer.php:207` (Phase 2 verify-Loop)

```php
if (BardLinkInserter::insertLinkIntoEntryWithHref(
    $candidate['sourceEntryId'], $candidate['anchorText'], $href, false, false
    // ← fehlt: $candidate['sentenceContext'] (oder aus $allSuggestions[i])
)) {
```

Phase 2 in `enrichWithSuggestionCountsStreamed` verifiziert
inbound-Kandidaten mit Dry-Run-Insert um die persistierten
`inboundSuggestionCount` zu berechnen. Ohne `expectedSentenceContext`
zählt der Loop Kandidaten als "verified" deren Real-Write wegen
`context_mismatch` aber rejected würde. **Persistierte Counts
über-zählen** systematisch um genau die Differenz, die der
ursprüngliche Bug aus `4e6573d` im Modal sichtbar gemacht hat.

Beachten: der candidate-Datensatz (line 159-163) trägt heute
`['sourceEntryId', 'anchorText', 'targetEntryId']` — kein
`sentenceContext`. Fix muss den Wert im Candidate-Build mit-führen
und in Phase 2 übergeben.

**Impact:** `inboundSuggestionCount` auf jedem Record kann zu
hoch sein. Sichtbar im Links-Report-Counter; verschwindet beim
Drill-in (Modal nutzt `suggestFiltered` post-`4e6573d` mit
Context-Pass).

### Empfehlung

**Eigener PR:** beide Sister-Bugs in einem Commit fixen, da identisches
Pattern.

1. Pin-Tests rot:
   - `OutboundSuggestionGrouper::groupAndFilter` rejected einen
     Suggestion mit Anchor im Entry aber falschem Sentence-Context.
   - `EntryIndexer::enrichWithSuggestionCountsStreamed` Phase 2
     verified-Count matched `suggestFiltered`-Count nach einem
     Context-Mismatch-Scenario.
2. Candidate-Shape in `EntryIndexer:159-163` um `sentenceContext`
   erweitern.
3. Beide Call-Sites auf 6-Argument-Form heben.
4. Regression-Pin (`Test 5` aus dem bisherigen Parity-Suite-Pattern):
   legacy null-safety bleibt auf Signature-Level
   (`BardLinkInserter:324 ?string … = null`).

**Architecture-Audit-Check (`linkwise:audit`):** ein Reflexions-Check
"jede `*::insertLinkIntoEntryWithHref(...)` Aufruf-Stelle die mit
exact 5 args ruft + im selben File `Suggestion`/`InboundSuggestion`
verwendet" wäre ein guter Drift-Detektor für künftige Stellen.
Niedrige Priorität bis das Pattern ein drittes Mal auftaucht.

---

## Klasse C — Reindex/Cache-Coherence (Schreibpfad-übergreifend)

### Pattern

Jeder Operations-Pfad, der die Output-Menge einer cached / persistierten
Computation verändert, muss eigenen Invalidations-Pfad triggern.
Pin-Set #44-#48 deckt **Schreib-Pfade pro Entry**. Aber: Index-
Algorithmus-Änderungen + **Frontend-State-Caches** sind eigene
Mutations-Pfade ohne automatische Invalidierung.

Gefixt in `cf98c39`:
- `IndexCommand` purged `InboundSuggestionCache::forgetMany(...)`
  nach `$indexer->save($records)`.

### Audit-Methode

Drei Kategorien durchgegangen:
1. **Server-Side derived-state Caches:** `InboundSuggestionCache`,
   `BrokenLinkScanCache`, persistierte `EntryRecord`-Counts.
2. **Cache-Bus / Modal-Cache:** `linkwise:scan:status`,
   `linkwise:applyrule:status`, etc. — Job-Status, nicht derived-state.
3. **Frontend-State-Caches:** `localEntries[].content_hash` Map in
   Vue-Komponenten (LinksReportTab, AutoLinkingTab, etc.).

### Kandidaten geprüft

| Cache / State | Invalidation auf… | Status |
|---|---|---|
| `InboundSuggestionCache` (5min TTL) | per-write: #44-#48; per-reindex: `cf98c39` | ✅ gedeckt |
| `BrokenLinkScanCache` (entry-hash-keyed) | Auto-Evict bei Hash-Drift (`BrokenLinkChecker` self-managed) | ✅ self-invalidating (Hash-key statt TTL) |
| Persistierte `EntryRecord.inboundSuggestionCount` | Reindex → `enrichWithSuggestionCountsStreamed` läuft Phase 2 frisch | ✅ — aber Phase 2 selbst hat Klasse-B Bug B-2 (siehe oben). |
| Persistierte `EntryRecord.outboundSuggestionCount` | dito (`countGroups` → `OutboundSuggestionGrouper`) | ⚠️ unter-zählt nicht — aber `OutboundSuggestionGrouper:28` filtert weniger streng als Real-Write (Klasse-B B-1) → **über-zählt** im selben Sinne wie B-2 |
| Job-Status-Cache-Keys (`linkwise:*:status`) | terminal-phase override + TTL | ✅ kein derived-state |
| **`localEntries[].content_hash` Map (Frontend)** | per-detailrelink über Response (`DetailModal.vue:556-559`) | ❌ **Sister-Bug — User-Report 2026-05-16** |

### Befunde

**Ein zusätzlicher Sister-Bug bestätigt** — direkt aus User-Smoke
zwischen den drei Klassen-Audit-Schritten gemeldet:

#### C-1: Frontend-`content_hash`-Map veraltet nach Async-Bulk

**Symptom (User-Wortlaut, 2026-05-16):** "Im Links-Report nach mehreren
Bulk-Unlinks per Modal (ohne Seiten-Reload zwischen den Modalen) erscheint
irgendwann grauer Toast: 'Entry was modified by another editor' — obwohl
nur Linkwise selbst geschrieben hat."

**Wurzel:**
1. `DetailModal::executeUnlink` (Z. 334-337) sammelt
   `entryHashes[r.entry_id] = this.getEntryHash(...)` aus dem
   parent-state `entries`/`localEntries`.
2. `detail-unlink-async` dispatcht ein Background-Command (exec),
   returnt `{ success: true }` ohne neue Hashes
   (`BulkJobsController:314`).
3. Background-Command saved Entries → Statamic content_hash auf
   Disk ändert sich.
4. Frontend-Map bleibt mit OLD-hash.
5. Nächster Bulk-Unlink im selben page-state schickt OLD-hash →
   `BulkUnlinkCommand:138-139` `verifyHashes` → per-record-skip
   mit `'modified'` → Toast "Entry was modified".

**Vergleich Re-Link-Pfad (funktioniert):** `DetailModal.vue:556-559`
empfängt `data.new_hash` aus Sync-Response und merged in
`entriesRef`. Sync-Pfad → Hash-Refresh trivial. Async-Pfad → kein
Response-Body verfügbar.

**Watcher-Lücke:** `LinksReportTab.vue:364-395` lauscht auf
`bulkState.lastCompletion` und refreshed `suggestionCounts` für
relevante Bulk-Kinds (`detailunlink` ist enthalten). Aber er
refreshed **nicht** `localEntries[].content_hash`. Der
`detailrelink`-Pfad (Z. 391-393) macht bereits `reloadEntries()`
nach Completion — das ist die fehlende Operation für die anderen
Bulk-Kinds.

**Impact:** verifiziert pro Tab:
- ✅ **AutoLinkingTab** (`AutoLinkingTab.vue:501-512`): Watcher
  ruft `this.fetchData()` post-`applyrule`-completion — re-fetcht
  inkl. frischer Hashes. Gedeckt.
- ✅ **UrlChangerTab** (`UrlChangerTab.vue:661-684`): Watcher ruft
  `this.runPreview()` post-`urlchanger`-completion — rebuildet
  `entryHashes`-Map über fresh `searchUrls` request (Z. 485-488).
  Plus `inertiaRouter.reload({ only: ['domains'] })` für orphan-
  Update. Gedeckt.
- ❌ **LinksReportTab** (`LinksReportTab.vue:364-395`): Watcher
  refreshed nur `suggestionCounts` für die "destruktiven" Kinds
  (`bulkunlink`/`detailunlink`/`urlchanger`/`applyrule`) — kein
  `reloadEntries()`. **DAS ist die Lücke** die der User-Report
  trifft.

C-1 ist also LinksReportTab-lokal; die anderen Tabs sind durch
ihre eigenen post-completion-refresh-Pfade bereits gedeckt.

**Fix-Optionen (Advisor-Set):**

(a) **`reloadEntries()` post-completion für `detailunlink`/
    `bulkunlink`/`urlchanger`/`applyrule` zu LinksReportTab's
    Watcher hinzufügen.** ⟶ 1-Zeiler. Wirft die ganze
    `entries`-Tabelle frisch aus dem Server. Geringes Frontend-
    Surface, aber Reload-Cost.

(b) **Backend liefert `new_hashes`-Map im terminal-status-Payload.**
    `BulkStatusWriter::terminal()`-Aufruf jeder Bulk-Command
    erweitert um `'entry_hashes' => $newHashes`. Frontend merged
    in `bulkState.lastCompletion`-Watcher. ⟶ stärker, weil
    chirurgisch, aber Touch in 5+ Commands.

(c) **Per-completion Hash-Refresh-API.** Frontend ruft
    `GET /cp/linkwise/entry-hashes?ids=...` nach jeder Completion.
    Trennt das Concern — aber zusätzlicher Round-Trip pro Bulk.

**Empfehlung:** Option (a) für **diese Klasse-C-Schließung**
— `reloadEntries()` zu den vier destruktiven Bulk-Kinds im
LinksReportTab-Watcher hinzufügen. Pin-Test simuliert zwei
Bulk-Unlinks back-to-back ohne Page-Reload, verifiziert dass der
zweite Bulk OK durchläuft.

Begründung gegen Option (b)/(c): UrlChangerTab und AutoLinkingTab
beweisen empirisch, dass post-completion-`fetchData()`/`runPreview()`-
Refresh ausreicht — kein Backend-Touch nötig. Konsistent zum
existierenden Pattern.

**Bekanntes Residual-Race (post-Option-(a)):** Der Layout-Poller
clearen `bulkState.active = null` (`LinkwiseLayout.vue:765`) BEVOR er
`recordCompletion()` aufruft (Z. 794) — der Watcher fired + startet
den Inertia-Partial-Reload. Reload-Roundtrip ist async (~100–800 ms).
In diesem Fenster kann der User einen neuen DetailModal über
`showDetail` (`LinksReportTab.vue:1143`) öffnen — die Methode liest
`localEntries[].content_hash` synchron aus dem noch nicht
aktualisierten Snapshot. Der nächste Bulk schickt OLD-hash für
genau diese Occurrence.

C-1 wandelt damit eine **100%-Failure-Rate auf zweitem Bulk** in eine
**race-window-Failure-Rate** — User muss innerhalb ~½ Sekunde nach
dem Completion-Toast klicken um sie zu treffen. Vollständige Race-
Schließung verlangt dass `showDetail` selbst fresh Hashes vom Server
fetcht (analog `SuggestionModal::loadSuggestionsForEntry`,
Z. 690-704). Eigener Folge-Fix, Scope ausserhalb C-1 — dokumentiert
als Klasse-7-Residual in [[architectural_health]].

### Empfehlung

**Eigener PR** als Folge-Fix zum subtitle-Smoke-Sprint, weil:
- Bug ist user-reported empirisch (nicht latent).
- Selbe Klasse wie #44-#48 — fügt sich in "post-write reads fresh"
  Schiene.
- Frontend-only Touch + 1 Pin-Test (Playwright `--grep` targeted).

In `architectural_health.md` wird Klasse 3 (Counter-Aggregation Multi-
SoT) NICHT durch C-1 berührt — das ist eine eigene Klasse 7: **Async-
Bulk-Response trägt keinen post-mutation State zurück**. Eintrag wird
in `architectural_health.md` ergänzt.

---

## Audit-Zusammenfassung

| Klasse | Sister-Bugs gefunden | User-facing? | Empfohlene Aktion |
|---|---|---|---|
| **A** — Indexer-Writer-Symmetrie | 0 (latentes Markdown-Stripping dokumentiert) | nein | Klasse schließen, kein Audit-Check |
| **B** — Filter-Apply-Argument-Parität | **2** (`OutboundSuggestionGrouper:28`, `EntryIndexer:207`) | ja (silent skip bei Apply / counter-drift) | gemeinsamer Fix-PR + Pin-Tests |
| **C** — Reindex/Cache-Coherence | **1** (Frontend `content_hash`-Map veraltet, user-reported) | **ja, user-reported** | Folge-Fix-PR mit Option (a) |

**Drei Fix-Sessions** sind die Frucht dieses Audits. Reihenfolge per
User-Impact:

1. **C-1** zuerst — direkt User-erlebt + reproduzierbar im aktuellen
   Build.
2. **B-2** (EntryIndexer Phase 2) — counter-drift, ähnliche User-
   Sichtbarkeit wie der Inbound-Modal-Bug aus PR `55b33b0` aber für
   die Links-Report-Tabellen-Spalte.
3. **B-1** (`OutboundSuggestionGrouper:28`) — Outbound-Modal silent
   skip; niedriger Impact-Score weil weniger Surface.

Findings ohne Wert-Beweis (Klasse A Markdown-Stripping) bleiben
dokumentiert + nicht gefixt per
[[feedback_refactor_must_prove_value]].
