# Linkwise V1.x Polish-Audit (Sprint-0 vor V2)

**Stand:** 2026-05-26 (Nacht-Audit)
**Methode:** zwei parallele read-only Explore-Agents — Code-Review-Pass + Dark-Mode-Audit
**Total:** 47 actionable Findings (30 Code + 17 Dark-Mode)
**Status:** **NICHTS automatisch gefixt** — User entscheidet morgen welche Findings angefasst werden. BLOCKING-Regeln (Pre-Flight, Blast-Radius, Build-Verify) gelten weiter.

---

## 0. Lese-Reihenfolge

Wenn du nur 10 Minuten hast: **§1.1 (HIGH Code)** + **§2.1 (HIGH Dark-Mode)** — das sind die User-sichtbaren oder Stabilitäts-kritischen Punkte.

Volle Liste in §1.x (Code) und §2.x (Dark-Mode), sortiert nach Severity. Pro Finding: Path:Line, Problem, empfohlener Fix.

---

## 1. CODE-REVIEW-FINDINGS

### 1.1 HIGH SEVERITY (Stabilität / Concurrency / Type-Safety)

#### CR-H-1 — Reference-in-foreach Loop Inconsistency Risk → **DOWNGRADED 2026-05-27**
- **Path:** `src/Http/Controllers/OutboundController.php:68-77`
- **Re-Klassifikation:** LOW (Code-Smell, kein HIGH-Bug)
- **Begründung 2026-05-27:** Untersuchung zeigt: beide foreach-Blöcke unsetzen $group + $target korrekt (Block 1 outside-both, Block 2 inside-outer+outside-outer). Semantisch äquivalent. Kein Failing-Test konstruierbar — per Advisor-Disziplin „Sister-Audit: jeder HIGH-Fix braucht Bug-reproduzierenden Test" → kein HIGH-Bug. Per `feedback_refactor_must_prove_value`: kein Refactor ohne Bug-Beweis. CR-M-8 (gleiche Klasse) auch mit Skeptizismus prüfen in Sprint-0.B.
- **Status:** Audit-Pass dokumentiert, **kein Code-Edit**.

#### CR-H-2 — Type-Tuple-Inkonsistenz in SafeEntrySaver::load()
- **Path:** `src/Support/SafeEntrySaver.php:17-26`
- **Was:** Return `[null, '']` bei not-found, aber `@return array{0: Entry|null, 1: string}` impliziert immer string-Hash
- **Warum:** Caller die nicht type-checken können auf null-Entry stoßen aber empty-string-Hash zurückbekommen — leise Falsch-Positives bei Hash-Vergleich
- **Fix:** `@return array{0: ?Entry, 1: string}` explizit dokumentieren oder `[null, null]` für Symmetrie

#### CR-H-3 — Atomic File Write ohne Disk-Space + Status-Feedback
- **Path:** `src/Support/AtomicJsonWriter.php:49-67`
- **Was:** Temp-File → rename → Fallback auf direct-write bei rename-Fail
- **Warum:** Direct-Write-Fallback kann mid-write abbrechen (disk full, kill); target file truncated; Caller weiß nicht ob write erfolgreich
- **Fix:** Nach Fallback-Direct-Write file-size oder hash verifizieren, detailed status statt bool returnen

#### CR-H-4 — JSON-Parse-Errors silent-degrade in mehreren Readern
- **Path:** `src/Support/JsonFileStore.php:79-91` (zentralisiert) vs. `src/Links/BrokenLinkReport.php`, `LinkwiseLinkMark` (direct `json_decode`)
- **Was:** Zentral-Reader loggt Korruption, ad-hoc Reader nicht
- **Warum:** Stille Korruptions-Propagation in non-zentralen Pfaden → keine Cache-Invalidation, keine Telemetrie
- **Fix:** Alle `json_decode()` Aufrufe auf `JsonFileStore::load()` migrieren

#### CR-H-5 — Job-Lock Phase-Registrierung manuell + drift-anfällig
- **Path:** `src/Support/JobLock.php:66-74` (ACTIVE_PHASES Array)
- **Was:** Array manuell gepflegt — Comment bei Line 66 dokumentiert vergangenen Bug ("checking-not-listed")
- **Warum:** Neue Command-Phase ('validating' o.ä.) ohne Array-Update → Job ist invisible für `activeJob()` → concurrent dispatches racen
- **Fix:** PHPUnit-Test der alle Command-Klassen nach `'phase' => '...'` strings greppt + ACTIVE_PHASES checkt, ODER Phases als Enum konsolidieren

### 1.2 MEDIUM SEVERITY (Architecture-Drift / Race-Conditions)

#### CR-M-6 — Cache::put() TTL-Mismatch zwischen Payload/Status-Paaren
- **Path:** `src/Commands/ApplyRuleCommand.php:50` + analoge in BulkUnlinkCommand, DetailUnlinkCommand
- **Was:** Payload-TTL 600s, Status-TTL 60-300s
- **Warum:** Bei Command-Run >10min läuft Payload-Cache aus → command failt silent auf empty payload
- **Fix:** Warn-Log bei Payload-Expire während Run; Heartbeat-Pattern der beide Keys refresh

#### CR-M-7 — BulkSnapshotStore Pre-Hash-Race
- **Path:** `src/Support/BulkSnapshotStore.php:199-227`
- **Was:** `recordPostHashesForEntries()` läuft NACH Snapshot-Recording
- **Warum:** Falls Entry zwischen Snapshot-Creation + Pre-Hash-Recording geändert wird, hash matched nicht
- **Fix:** Pre-Hashes zusammen mit `record()` übergeben, nicht nachgereicht

#### CR-M-8 — Nested Reference-Modification ohne Scope-Guard
- **Paths:** `src/Http/Controllers/UrlChangerController.php:44`, `src/Reports/DomainReport.php:104`
- **Was:** `foreach ($entries as &$entry)` ohne `unset()` danach
- **Warum:** Reference persistiert, spätere Iterationen im selben Scope können Last-Element mutieren
- **Fix:** Explizites `unset($entry)` nach jeder `&`-foreach

#### CR-M-9 — Fehlende Hash-Verification in LinkInsertCommand
- **Path:** `src/Commands/LinkInsertCommand.php:52-53`
- **Was:** Lädt Entries ohne `SafeEntrySaver::verifyHashes()` (vs. ApplyRuleCommand Line 90 macht's)
- **Warum:** User-Edit während Insert → Insert auf stale content
- **Fix:** `verifyHashes()` am Start von handle() (Sister-Bug zur Klasse-7-Saga aus Memory)

#### CR-M-10 — OutboundSuggestionGrouper-Result nicht null-checked
- **Path:** `src/Http/Controllers/OutboundController.php:43`
- **Was:** `$result` direkt verwendet ohne null-guard
- **Warum:** Wenn `groupAndFilter()` jemals null returnt (defensive Edge-Case), KeyAccessError auf 'groups'/'count'
- **Fix:** Type-Guard mit early-return

#### CR-M-11 — File-Rotation ohne Locking in DashboardController
- **Path:** `src/Http/Controllers/DashboardController.php:526-530`
- **Was:** `@rename($logPath, $logPath.'.1')` ohne flock()
- **Warum:** Concurrent requests check size + try rotate gleichzeitig, log-lines verloren
- **Fix:** flock() für mutual exclusion bei Rotation

#### CR-M-12 — ContentSafetyValidator zweimal in Save-Pfad
- **Path:** `src/Support/SafeEntrySaver.php:74-79` + `:115`
- **Was:** Erst absolute Validation, dann diff-Validation, beide Sides bereits normalisiert
- **Warum:** Wenn Normalisierung false-positive einführt, diff-mode catched es nicht (beide Sides "drift in" zu identical state)
- **Fix:** Test-Case „entry mit pre-existing adjacent same-mark, normalize merged them, diff-mode false-fires" oder bestätigt ok

### 1.3 MEDIUM-LOW SEVERITY (Code-Smells)

#### CR-ML-13 — Catch-All in BardLinkInserter ohne Differenzierung
- **Path:** `src/Support/BardLinkInserter.php:136-138, 250-252, 334-336, 426-428, 629-631, 878-880`
- **Was:** 6× `catch (\Throwable) { return 0/false/null; }`
- **Warum:** OOM, I/O-Errors gleich behandelt wie Test-Config-Issues; Production-Errors silent
- **Fix:** ConfigNotFoundException differenziert behandeln, andere mit Log + Re-Throw

#### CR-ML-14 — Locale-Type-Safety in SuggestionEngine
- **Path:** `src/Suggestions/SuggestionEngine.php:87-90` + `:159`
- **Was:** Drei-Wege null/string-Check verteilt über Constructor + Runtime
- **Fix:** Helper-Method `shouldFilterByLocale()` zentralisieren

#### CR-ML-15 — Direct file_put_contents() statt AtomicJsonWriter
- **Path:** `src/Links/BrokenLinkReport.php:34, 119, 208, 285`
- **Was:** 4× direct-write trotz AtomicJsonWriter-Existenz
- **Warum:** Crash während Save → file truncated
- **Fix:** Auf AtomicJsonWriter migrieren (konsistent mit anderen state-files)

#### CR-ML-16 — Long Method: BardLinkInserter::insertAllLinksIntoEntryWithHref()
- **Path:** `src/Support/BardLinkInserter.php:240-295`
- **Was:** 55 lines, field-type-routing (bard/replicator/markdown) 3× wiederholt
- **Fix:** `FieldTypeRouter`-Klasse extrahieren

#### CR-ML-17 — Long File: AuditCommand (2365 Zeilen)
- **Path:** `src/Commands/AuditCommand.php:69-200+`
- **Was:** Mega-handle() mit 20+ inline-Audit-Lambdas
- **Fix:** Audit-Checks in eigene Klassen `Audit\CheckAutoLink`, `Audit\CheckIndexParity` etc. mit shared interface

#### CR-ML-18 — Magic Numbers in BulkSnapshotStore
- **Path:** `src/Support/BulkSnapshotStore.php:33, 41`
- **Was:** `RETENTION_DAYS=30`, `MAX_ENTRIES_PER_SNAPSHOT=1000` hardcoded
- **Fix:** `config('linkwise.bulk_snapshot_retention_days', 30)` + Warn-Log bei trimmed

#### CR-ML-19 — Unvalidated Input in DetailUnlinkCommand
- **Path:** `src/Commands/DetailUnlinkCommand.php:56-62`
- **Was:** `$payload['replacements']`, `$payload['entry_hashes']` ohne type-guard
- **Fix:** Explizite `is_array()`-Validation mit early-error

#### CR-ML-20 — Silent-Skip in EntryFieldWalker bei Blueprint-Errors
- **Path:** `src/Support/EntryFieldWalker.php:32-36`
- **Was:** `catch (\Throwable) { ... }` silent
- **Warum:** Legitime Schema-Errors (missing fields, custom-types) silent → incomplete indexing
- **Fix:** Log Entry-ID + Exception-Type; audit-check für invalid blueprints

### 1.4 LOW SEVERITY (Polish)

| ID | Path | Was | Fix |
|---|---|---|---|
| CR-L-21 | `src/Support/JsonFileStore.php:15-16` | Misleading comment ("every reader") | Clarify: representative, not exact |
| CR-L-22 | mehrere Commands/Controller | Cache-Key-Strings dupliziert (`'linkwise:applyrule:status'` etc.) | Zentralisieren als `CacheKey`-Enum |
| CR-L-23 | `src/Support/SafeEntrySaver.php:273-278` | Hash()-Docblock unklar was gehashed wird | Note: „normalized Bard-JSON, adjacent same-mark merged" |
| CR-L-24 | EntryConflictException, ContentCorruptionException | Inkonsistente Capitalization in Messages | Sentence case standardisieren |
| CR-L-25 | `ApplyRuleCommand.php:36`, `EntryIndexer.php:31` | `@throws` fehlt obwohl throws | Docblocks ergänzen |
| CR-L-26 | `src/Suggestions/SuggestionEngine.php:50-54` | Catch-Throwable in Config-Load loggt nichts | Log non-ConfigNotFoundException |
| CR-L-27 | `src/Support/BulkSnapshotStore.php:83-86` | `array_unique` mit loose-comparison | `SORT_STRING` flag |
| CR-L-28 | `src/Support/ContextExtractor.php:105-142` | `mb_strpos === false` implizit | Explizite `!== false`-Checks |
| CR-L-29 | `src/AutoLink/AutoLinkApplier.php:73` ff. | Einige config()-Calls ohne Default | Default überall ergänzen |
| CR-L-30 | `src/Http/Controllers/DashboardController.php:543` | Volle Exception-Message in Activity-Log | Sanitize / redact sensitive keys |

---

## 2. DARK-MODE-FINDINGS

### 2.1 HIGH (Unleserlich)

#### DM-H-1 — Strikethrough-Text unsichtbar im Dark-Mode → **FIXED 2026-05-27**
- **Path:** `resources/js/components/dashboard/BrokenLinksTab.vue:265, 285`
- **Was:** `line-through text-gray-400` — grau auf grau
- **Fix applied:** `text-gray-400 dark:text-gray-600` an beiden Stellen
- **Verify:** Build durch (`addon-AnNTO3CS.js`), publish gegen prose-peak-test, bundle enthält `dark:text-gray-600`. User-Browser-Smoke pending.

#### DM-H-2 — Placeholder-Text schwer lesbar → **DOWNGRADED 2026-05-27**
- **Path:** `resources/js/components/dashboard/BrokenLinksTab.vue:223-237`
- **Re-Klassifikation:** kein aktueller Bug — das native `<input>` bei 223-237 hat **kein placeholder-Attribut** (verifiziert via grep). Statamic-`<Input>` bei Line 28 handhabt Dark-Mode-Placeholder über CP-CSS-Variablen automatisch (per `frontend_implementation_axiom`).
- **Status:** Kein Edit. Audit-Agent hat über "+ andere Input-Felder" verallgemeinert; keine konkrete Stelle bricht im Dark-Mode.

#### DM-H-4 — Badge `dark:text-COLOR-400` auf `dark:bg-COLOR-900/30` unleserlich → **FIXED 2026-05-27**
- **Quelle:** User-Browser-Smoke 2026-05-27 ("Server Unreachable" yellow + "add +N" amber Badges hellgrau unleserlich)
- **Klasse:** 12 Vorkommen über 9 Dateien — Badge-Pattern mit colored-bg + dark:text-COLOR-400 Text. Sister-Bug-Sweep statt punktuell (per `feedback_proactive_sister_bug_search`).
- **Fix applied:** Alle `dark:text-COLOR-400` → `dark:text-COLOR-300` in Badge-Kontexten (red/amber/yellow/orange/blue/green/purple).
- **Files:** SuggestedPhrase, OverviewTab, LinksReportTab (3 Stellen), BrokenLinksTab (9 Stellen), TargetKeywordsTab, RuleListTable, RulePreviewModal (3 Stellen), SuggestionModal (4 Stellen), DetailModal.
- **Excluded:** SuggestionModal:139 (Toggle-Button "Show/Hide ignored", kein Status-Badge — anderer visueller Kontext per Advisor).
- **Verify:** Build durch (`addon-D7e_GkjN.js`), Bundle enthält alle 7 Color-300-Varianten, Vitest 186/186. User-Browser-Smoke pending.

#### DM-H-5 — `<details>` heller bg im Dark-Mode statt Statamic-Pattern → **FIXED 2026-05-27**
- **Quelle:** User-Browser-Smoke 2026-05-27 ("details hat im darkmode hellgrau bis fast weiss")
- **Path:** `NotificationsAccordion.vue:11+13`, `OverviewTab.vue:40+42`
- **Was:** `dark:bg-gray-800/40` (Opacity-Pattern) wich von dominantem Codebase-Pattern `dark:bg-gray-800` (solid) ab — 14+ andere Komponenten nutzen solid. Hypothese: 40%-Opacity rendert im Statamic-CP-Container unzuverlässig.
- **Fix applied:** `dark:bg-gray-800/40` → `dark:bg-gray-800` (solid) für Details-Panel-bg; `dark:hover:bg-gray-800/60` → `dark:hover:bg-gray-700` (helleren Grau-Shade als Hover-Indikator statt Opacity).
- **Excluded:** `BrokenLinksTab.vue:191` (ignored-row-Fade — Opacity dort intentional für "Row ist gemuted").
- **Verify:** Build, Vitest 186/186, Bundle published. User-Browser-Smoke pending.

#### DM-H-3 — Link-Hover ohne Dark-Hover-Variante → **FIXED 2026-05-27**
- **Path:** `resources/js/components/dashboard/BrokenLinksTab.vue:289`
- **Was:** `text-gray-700 dark:text-gray-300 hover:underline` aber kein farbiger Hover-Indikator
- **Fix applied:** `hover:text-blue-600 dark:hover:text-blue-400` ergänzt (Pattern aus OverviewTab.vue:287/292)
- **Verify:** Build + Publish + Bundle-Grep für `dark:hover:text-blue-400` durch.

### 2.2 MEDIUM (Optisch falsch)

| ID | Path:Line | Was | Fix |
|---|---|---|---|
| DM-M-4 | `DetailModal.vue:68` | `opacity-40 line-through` zu schwach | `dark:opacity-50 dark:text-gray-600` |
| DM-M-5 | `OverviewTab.vue:110-114` | Locale-Badge `bg-gray-100 dark:bg-gray-800` zu hell | `dark:bg-gray-900 dark:text-gray-300` |
| DM-M-6 | `LinksReportTab.vue:119` | Loading-Spinner `text-gray-400` ohne dark | `text-gray-400 dark:text-gray-600` |
| DM-M-7 | `BrokenLinksTab.vue:40, 229-235` | Disabled-Input ohne dark-opacity | `dark:disabled:opacity-40` |
| DM-M-8 | `SuggestedPhrase.vue:12` | Hover-Opacity-70 auf dark zu subtil | `dark:hover:opacity-100` |
| DM-M-9 | `LinksReportTab.vue:192` | Red-Badge `dark:text-red-400` zu dunkel | `dark:text-red-300` |
| DM-M-10 | `LinksReportTab.vue:193, 203` | Amber-Badge `dark:text-amber-400` zu dunkel | `dark:text-amber-300 dark:bg-amber-900/40` |
| DM-M-11 | `OverviewTab.vue:73` | Dismiss-Button `opacity-50` kaum sichtbar | `opacity-60 dark:opacity-70 dark:hover:opacity-100` |

### 2.3 LOW (Konsistenz)

| ID | Path:Line | Was | Fix |
|---|---|---|---|
| DM-L-12 | `SortableHeader.vue:10, 15` | `text-gray-900! dark:text-gray-400!` mit `!` | Ohne `!` + `dark:text-gray-300` |
| DM-L-13 | `HelpIcon.vue:4` | `/60` Opacity zu schwach | `/70` bzw. `dark:/80` |
| DM-L-14 | `SuggestionModal.vue:393` | `dark:hover:bg-gray-800/50` zu subtil | `dark:hover:bg-gray-700` |
| DM-L-15 | `MultiSelect.vue:38` | Active-Item-Highlight nicht deutlich | `dark:bg-gray-600` |
| DM-L-16 | `MultiSelect.vue:15` | Focus-Ring `ring-blue-500` zu dunkel | `dark:focus-visible:ring-blue-400` |
| DM-L-17 | `BrokenLinksTab.vue:79-96` | Conflict-Banner border `/50` zu schwach | `dark:border-yellow-700` |

---

## 3. EMPFOHLENE SPRINT-0-REIHENFOLGE

### Sprint 0.A — Stabilität (HIGH Code + HIGH Dark-Mode)
- CR-H-1 .. CR-H-5 (5 HIGH Code)
- DM-H-1 .. DM-H-3 (3 HIGH Dark-Mode)
- **Geschätzter Aufwand:** 2-3 Tage
- **Test-Pflicht:** Pin-Tests für CR-H-5 (Phase-Registry), Regression-Test für CR-H-3 (Atomic Write Status)

### Sprint 0.B — Code-Quality (MEDIUM + MEDIUM-LOW Code + MEDIUM Dark-Mode)
- CR-M-6 .. CR-M-12 (7 MEDIUM Code)
- CR-ML-13 .. CR-ML-20 (8 MEDIUM-LOW Code)
- **CR-H-3b — Sister-Bug-Follow-up (entdeckt 2026-05-27 bei CR-H-3-Fix):** Beide AtomicJsonWriter-Caller (EntryIndexer::save Z.566, DomainReport::saveAttributes Z.152) ignorieren den bool-Return. Truncation-Fix in CR-H-3 macht den Return jetzt verlässlich, aber niemand hört zu. Klärung: Log-Warn-Eskalation oder Exception-Wurf? Eigener Mini-PR in Sprint-0.B.
- DM-M-4 .. DM-M-11 (8 MEDIUM Dark-Mode)
- **Geschätzter Aufwand:** 4-5 Tage
- **Spezial-Pflicht:** Mutator-Parity-Test gegen `auditMutatorParity` für CR-M-9 (BLOCKING memory `feedback_mutator_parity`)

### Sprint 0.C — Polish (LOW Code + LOW Dark-Mode)
- CR-L-21 .. CR-L-30 (10 LOW Code)
- DM-L-12 .. DM-L-17 (6 LOW Dark-Mode)
- **Geschätzter Aufwand:** 1-2 Tage
- **Bonus:** als V1.3.0-Release-Candidate, Changelog mit „Polish, Stabilization, Dark-Mode-Improvements"

### **Total Sprint-0:** ~7-10 Tage vor V2-Sprint-Start

---

## 4. NICHT IN SCOPE FÜR SPRINT-0

- AuditCommand-Refactor (CR-ML-17, 2365 Zeilen): zu groß, eigener Track wenn überhaupt — kein User-facing Bug. **DEFER.**
- BardLinkInserter Long-Method (CR-ML-16, 55 Zeilen): kein konkreter Bug, nur code-smell. **DEFER.**
- FieldTypeRouter-Extraktion: pre-mature abstraction, kein konkreter Pain. **DEFER.**

Per `feedback_refactor_must_prove_value` Memory: kein Refactor ohne Stabilitäts/Wert/Regression-Beweis.

---

## 5. FILES TO TOUCH (für Auto-PR-Sketch)

Sprint 0.A:
- `src/Http/Controllers/OutboundController.php`
- `src/Support/SafeEntrySaver.php`
- `src/Support/AtomicJsonWriter.php`
- `src/Support/JsonFileStore.php`
- `src/Support/JobLock.php`
- `src/Links/BrokenLinkReport.php` (json-decode-Audit)
- `resources/js/components/dashboard/BrokenLinksTab.vue`

Per CLAUDE.md BLOCKING: Blast-Radius-Audit + Build-Verify nach jedem Frontend-Edit + `php artisan linkwise:audit` gegen `~/Herd/prose-peak-test` vor „kannst testen".

---

## 6. TEST-INVESTMENTS DIE FEHLEN

Aus den Findings ableitbar:

1. **Phase-Registry-Pin** (für CR-H-5) — greppt Command-Klassen + verifiziert ACTIVE_PHASES-Cover
2. **JSON-Decoder-Audit-Pin** (für CR-H-4) — verifiziert dass keine `json_decode()` außerhalb JsonFileStore mehr existiert
3. **ContentSafetyValidator-Normalization-Edge-Case** (für CR-M-12) — pre-existing adjacent same-mark fragments + normalize + diff false-fire-Check
4. **LinkInsertCommand-verifyHashes-Pin** (für CR-M-9) — Sister-Bug zu BulkUnlink/ApplyRule Hash-Verify-Pattern
5. **AtomicJsonWriter-Status-Detail-Test** (für CR-H-3) — verify-after-fallback-write

---

## 7. QUELLEN

- Code-Review-Pass: `Explore`-Agent ab16cc015535afa6a (Background-Run 2026-05-26 Abend)
- Dark-Mode-Audit: `Explore`-Agent ac426c0e994bda1ed (Background-Run 2026-05-26 Abend)
- Beide Audits sind read-only — keine Code-Änderungen wurden gemacht
