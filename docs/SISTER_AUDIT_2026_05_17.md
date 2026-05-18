# Sister-Bug Audit — 2026-05-17

**Auslöser:** User-Smoke 2026-05-17, Bug #3 — Bulk-Unlink aus AutoLinkingTab Preview-Modal refreshed nicht die Rule-Tabelle, exakt dasselbe Pattern wie Klasse 7 im LinksReportTab das per PR #49/#55 schon gefixt war. User-Korrektur:

> "Du sollst so arbeiten, dass Du nicht fokussiert dinge löst, sondern schaust wo es ähnliche fälle geben kann!!!"

Memory: `feedback_proactive_sister_bug_search.md` codifiziert die Lektion. Dieses Doc ist der systematische Audit-Pass über alle Klassen aus `architectural_health.md`.

## Methode

Pro Klasse:
1. **Pattern-Grep** über Codebase mit pre-defined Heuristik.
2. **Hits-Tabelle**: alle gefundenen Stellen + Status (`✅ covered` / `❌ gap` / `N/A` mit Begründung).
3. **Struktureller Pin** wenn machbar (PHPUnit Reflexion oder Vitest Source-grep) — verhindert Re-Manifestation strukturell.
4. **Lücken-Behebung**: eigener PR pro Klasse, klare Grenzen. Kein Bündel.

Zeitlimit pro Klasse: 15min Grep + Diskussion, dann entweder Fix-PR oder explizit als "no gap found, here's why" dokumentieren.

## Wellen

| Welle | Klasse | Status | PR |
|---|---|---|---|
| 1 | Klasse 7 Backend (async-bulk + Activity-Log skip-records parity) | ✅ DONE — strukturell geschlossen | #59 |
| 2 | Klasse 7 Frontend (post-completion-reload parity) | ✅ DONE — strukturell geschlossen | (this PR) |
| 3 | Klasse 4.x (filter-apply argument parity) | ✅ DONE — strukturell geschlossen | (this PR) |
| 4 | Klasse 9 (terminal-status shape parity — sister-suche `errors`-Field) | pending | — |
| 5 | Klasse 1.x latent (ambiguous-context silent-wrong-wrap) | pending | — |
| 6 | Klassen 1/2/3/4/6/8 Verify-Pass | pending | — |

## Welle 1 — Klasse 7: async-bulk + Activity-Log skip-records parity

**Pattern:** Bulk-Command schreibt mit `verifyHashes`-Gate, hash-conflicted entries werden silent geskippt. Activity-Log-Drawer rendert per-entry Skip-Records aus `recordBulkSkipped()`. Frontend nach Bulk-Completion erwartet aktuelle Counts/Hashes — wenn der Watcher nichts refreshed, bleibt UI stale.

**Sub-Pattern A (Backend):** Jeder mutating Command muss nach `verifyHashes` per-entry skip-records schreiben.
**Sub-Pattern B (Frontend):** Jede Tab-Komponente mit `bulkState.active`-Watcher muss bei terminal phase einen Reload-Pfad triggern für die kinds die sie selbst ausführt.

### Grep Backend

```bash
grep -rnE "SafeEntrySaver::verifyHashes|recordBulkSkipped" src/Commands/
```

### Hits Backend

| File | Status | Note |
|---|---|---|
| `BulkUnlinkCommand:153,196,215` | ✅ covered | verifyHashes + recordBulkSkipped beide präsent |
| `DetailUnlinkCommand:241,335` | ✅ covered | verifyHashes + recordBulkSkipped beide präsent |
| `LinkInsertCommand:193,269,302` | ✅ covered | verifyHashes + recordBulkSkipped beide präsent |
| `UrlChangerApplyCommand:227,314` | ✅ covered | verifyHashes + recordBulkSkipped beide präsent |
| `ApplyRuleCommand:90,289` | ❌ gap → ✅ fixed PR #59 | hatte verifyHashes aber kein recordBulkSkipped. Hash-conflict-Skips landeten nirgends. Welle 1 fix: single + per-rule-Loop schreiben recordBulkSkipped mit reason 'modified', anchor=$rule->keyword |
| `IndexCommand`, `CheckLinksCommand`, `NormalizeBardCommand`, `SeedTestDataCommand`, `AuditCommand` | N/A | non-mutating oder eigene Semantik, kein verifyHashes |

### Grep Frontend

```bash
grep -rnE "bulkState|\\\$watch.*bulkState|reloadEntries|fetchData|runPreview|inertiaRouter.reload" resources/js/components/dashboard/ resources/js/components/pages/
```

### Hits Frontend

| File | Trigger-Kinds | Post-completion Action | Status |
|---|---|---|---|
| `LinksReportTab.vue:378-453` | detailrelink/bulkunlink/detailunlink/urlchanger/applyrule | `reloadEntries()` (Inertia partial) | ✅ covered (PR #49/#55) |
| `UrlChangerTab.vue:661-679` | urlchanger | `runPreview()` + `inertiaRouter.reload(domains)` | ✅ covered |
| `BrokenLinksTab.vue:708` | inline post-action | `inertiaRouter.reload(['brokenData','entryHashes'])` | ✅ covered |
| `AutoLinkingTab.vue:501-512` (dispatchApplyMultiple watcher) | applyrule | **WAR `this.fetchData()` → NICHT DEFINIERT, silent no-op seit Tab-Extraktion** | ❌ gap → ✅ fixed PR #59 (inertiaRouter.reload) |
| `AutoLinkingTab.vue:1087-1098` (unlinkSelectedFromPreview watcher) | detailunlink | **WAR nur Selection-Reset, kein Reload** | ❌ gap → ✅ fixed PR #59 (inertiaRouter.reload) |
| `DetailModal.vue:417` | reads `bulkState.lastCompletion` | nur intern für Modal-State, kein Tab-Refresh nötig | N/A |
| `ActivityPage.vue:670` | comment-only Reference | N/A |

### Struktureller Pin

**Backend (PR #59):** `tests/Unit/Commands/BulkCommandSkipRecordParityTest.php` — Source-Grep über Commands, Contract-Sanity-Test. 2 Tests / 27 Assertions. Klasse 7 Backend-Hälfte unmöglich neu zu manifestieren.

**Frontend (Welle 2 TODO):** Vitest source-grep über `resources/js/components/dashboard/*.vue` + `resources/js/components/pages/*.vue` — jede Komponente die `bulkState` importiert und einen `$watch`-Pattern auf `bulkState.active` nutzt muss bei terminal-phase einen Reload-Call enthalten (`inertiaRouter.reload` | `runPreview` | `reloadEntries` | `fetchData`-IF-defined). Sister-test zum PHPUnit-Pin.

### Welle-1-Status

**Klasse 7 Backend-Hälfte strukturell geschlossen.** Frontend-Pin als Welle 2 vorgemerkt (advisor: "nicht-blockend, sister-symmetrisch, trivial").

3 Sister-Stellen gefixt:
- ApplyRuleCommand recordBulkSkipped (single + multi)
- AutoLinkingTab unlinkSelectedFromPreview → inertiaRouter.reload
- AutoLinkingTab dispatchApplyMultiple → inertiaRouter.reload (hidden 4th sister, silent no-op)

### Known follow-up gaps (NICHT in Welle 1, dokumentiert)

**`pollApplyAsyncStatusOnce` done-branch (line 870-871 post-fix):** updated `rule.linked_count += linksAdded` inline, aber NICHT `linked_elsewhere_count`/`not_insertable_count` die `wouldLinkForRule()` feeden. Stale-Count-Hypothese — hypothetical, nicht user-reported. Welle 2 audit-target. Optionen: (a) gleiche Inertia-Reload-Pattern wie dispatchApplyMultiple (konsistent, aber redundante Network-Roundtrips wenn auch nur 1 entry geapplied), (b) inline alle 4 Counter-Felder updaten (kompliziert + race-anfällig), (c) als acceptable status quo doku.

---

## Welle 2 — Klasse 7 Frontend: post-completion-reload Vitest Pin

**Pattern:** Tab/Page-Komponente subscribt zu `bulkState.active` via `this.$watch(() => bulkState.active, ...)`. Wenn der Watcher beim Übergang zu `null` (terminal phase) keinen Refresh-Pfad triggert, bleibt die UI stale (counts, hashes, button-disabled-Logik).

### Grep

```bash
grep -rlE "bulkState" resources/js/components/dashboard/ resources/js/components/pages/
grep -nE "this\.\$watch.*bulkState" <those-files>
```

### Hits (alle Komponenten mit `bulkState`-Import)

| File | Hat $watch? | Refresh-Call im Watcher? | Status |
|---|---|---|---|
| `dashboard/AutoLinkingTab.vue` (2 Watcher) | ✅ applyrule + detailunlink | ✅ inertiaRouter.reload × 2 (PR #59) | covered |
| `dashboard/LinksReportTab.vue` | ✅ `lastCompletion` (anderes Pattern, eigener Pin `LinksReportBulkRefreshPin`) | ✅ reloadEntries() (PR #49/#55) | covered (separate pin) |
| `dashboard/UrlChangerTab.vue` | ✅ urlchanger | ✅ runPreview() + inertiaRouter.reload | covered |
| `dashboard/BrokenLinksTab.vue` | ❌ kein $watch, inline-Reload | N/A | non-applicable |
| `pages/BrokenLinksPage.vue` | ❌ kein $watch, nur active-check | N/A | non-applicable |
| `dashboard/DetailModal.vue` | ✅ active+completion | ❌ kein Tab-Refresh — **EXEMPT** | exempt (Modal-internal, parent LinksReportTab owns refresh) |
| `dashboard/SuggestionModal.vue` | ❌ nur active-check, kein $watch | N/A | non-applicable |

### Struktureller Pin

**NEU:** `tests/Vue/services/BulkStateWatcherReloadParityTest.test.js` (2 Tests). Source-grep über `dashboard/*.vue` + `pages/*.vue`:

1. **Contract-Test**: jede Komponente die `bulkState` importiert UND `$watch(() => bulkState.*, ...)` nutzt MUSS im Watcher-Body einen Refresh-Call enthalten (`inertiaRouter.reload` | `router.reload` | `runPreview` | `reloadEntries` | `fetchData`). Sonst → fail mit Pointer auf Sister-Pattern. EXEMPT_WATCHERS-Map für legitime Ausnahmen (z.B. Modal-internal state) mit schriftlicher Begründung.
2. **Sanity-Pin**: explizit dass AutoLinkingTab (gerade gefixt PR #59) den Contract erfüllt — verhindert false-green durch Method-Rename / Regex-Drift.

Regression-Beweis: Manuell `inertiaRouter.reload(` zu `({` zerstückelt → Test failed mit exakter Error-Message + Komponenten-Liste, dann restored → grün.

### Welle-2-Status

**Klasse 7 vollständig strukturell geschlossen** (Backend + Frontend). Jeder Neue Bulk-Command der `verifyHashes` ruft MUSS `recordBulkSkipped` rufen ([[BulkCommandSkipRecordParityTest]]). Jede neue Tab/Page-Komponente mit `bulkState`-Watcher MUSS Refresh-Call enthalten (BulkStateWatcherReloadParityTest).

---

## Welle 3 — Klasse 4.x: filter-apply argument parity

**Pattern:** `BardLinkInserter::insertLinkIntoEntryWithHref` hat optionalen 6. Arg `?string $expectedSentenceContext = null`. Dry-Run-Filter / Audit / Verify-Loop kann mit 5 Args rufen (kein context) während Real-Write mit 6 ruft (mit context) → User-Modal zeigt Suggestion, Apply silent-rejected mit `context_mismatch`. Oder umgekehrt: persistierter Counter über-zählt weil Verify-Loop permissiver ist als die User-Filter.

### Grep

```bash
grep -rnE "insertLinkIntoEntryWithHref" src/
```

### Hits (alle 9 Call-Sites)

| Site | Args | sentence_context flow | Status |
|---|---|---|---|
| `Suggestions/OutboundSuggestionGrouper:35` | 6 | `$s->sentenceContext` | ✅ covered (PR #53 B-1) |
| `Suggestions/InboundEngine:158` | 6 | `$s->sentenceContext` | ✅ covered (commit `4e6573d`) |
| `Indexer/EntryIndexer:231` | 6 | `$candidate['sentenceContext']` | ✅ covered (PR #52 B-2) |
| `Commands/LinkInsertCommand:201` | 6 | `$expectedSentenceContext` | ✅ baseline real-write |
| `Commands/AuditCommand:753` `checkDryRunAgreesWithLinkStatus` | 5 | N/A | ✅ NOT a gap — symmetric to AutoLinkApplier (rule-based, both preview + apply use 5-arg by design) |
| `Commands/AuditCommand:2196` `checkSuggestionInsertable` | 5 → 6 | **fehlte** ❌ → ✅ fixed Welle 3 | Audit konnte PASS sagen während real apply FAILT — false negative in unserem eigenen Audit |
| `Subscribers/AutoLinkOnEntrySaveSubscriber:94` | 4 | N/A | ✅ NOT a gap — fire-and-forget design (kein preview, kein expected context) |
| `AutoLink/AutoLinkApplier:267` | 6 | forwards | ✅ caller's responsibility |
| `Support/BardLinkInserter:219` | 3 | N/A | ✅ internal helper, default args |

### Fix (1 site)

`AuditCommand::checkSuggestionInsertable` — Signatur um `?string $sentenceContext = null` erweitert, named arg `expectedSentenceContext: $sentenceContext` an Inserter forward, beide Caller (line 258 auditInbound + line 296 auditOutbound) passen `$s->sentenceContext`.

### Struktureller Pin

**NEU:** `tests/Unit/Architecture/SuggestionContextArgumentParityTest.php` (1 Test, regression-bewiesen). Source-Grep über `src/`:

- Jeder Call-Site von `insertLinkIntoEntryWithHref` MUSS `sentenceContext` oder named arg `expectedSentenceContext:` enthalten — sonst Fail mit Pointer auf Klasse 4.x.
- `EXEMPT_FILES`: AutoLinkApplier, AutoLinkOnEntrySaveSubscriber, LinkInsertCommand (baseline), BardLinkInserter — alle mit dokumentierter Begründung.
- `PARTIAL_EXEMPT_FILES`: AuditCommand `checkDryRunAgreesWithLinkStatus` (rule-based symmetric path), aber `checkSuggestionInsertable` IS in scope.

Regression-Beweis: `sed`-deleted die named-arg-Zeile + die `$s->sentenceContext`-Forwards → Test fail mit exakter Source-Line-Zuordnung → restored.

### Welle-3-Status

**Klasse 4.x vollständig strukturell geschlossen.** 4 bekannte Manifestationen historisch (3 gefixt + 1 in Welle 3) + struktureller Pin verhindert künftige Drift.

### Bonus-Erkenntnis: Audit-Symmetrie vs Real-Symmetrie

Der Original-Audit-Check-Plan (Klasse 4.x in `architectural_health.md`) erwähnte:
> Audit-Check-Idee: linkwise:audit prüft jede *::insertLinkIntoEntryWithHref()-Call-Stelle die mit exact 5 Args ruft + im selben File `Suggestion`/`InboundSuggestion` verwendet → warn.

Welle 3 hat das **als PHPUnit struktureller Pin** statt als Runtime-Audit-Check umgesetzt — fail-vor-merge ist stärker als warn-zur-Laufzeit.

---

## Welle 4 — Klasse 9: terminal-status shape parity sister-suche

*pending — `errors`-Field-Drift in `Cache::put('linkwise:<kind>:status')` writes; bisher nur succeeded/skipped audited*

---

## Welle 5 — Klasse 1.x latent: ambiguous-context silent-wrong-wrap

*pending*

---

## Welle 6 — Klassen 1/2/3/4/6/8 Verify-Pass

*pending — Verify dass closed gemeldete Klassen tatsächlich sister-frei sind*
