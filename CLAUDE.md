# Linkwise — Claude Code Rules

## BLOCKING: Pre-Flight before "kannst testen"

Bevor du dem User irgendwas zum Testen gibst, MUSS jeder Punkt erfüllt sein:

1. **Domain-First Brainstorm**: aus User-Sicht ("was wäre dem User schlimm?"), 5+ Failure-Modi pro Aktion. Keine Code-Begriffe. Mapping pro Punkt zu Unit-Test ODER Audit-Check ODER explizit als bekanntes Risiko.
2. **Tests laufen** — alle grün, neue + Regression.
3. **`php artisan linkwise:audit`** gegen `~/Herd/prose-peak-test`. Exit 0 oder jedes pre-existing failure explizit benannt.
4. **Real-flow proof** für mutating changes (tinker oder Playwright, kein "es sollte funktionieren").

Test-Verantwortung liegt bei Claude, nicht beim User. Findet der User einen funktionalen Bug den ein Test/Audit gefangen hätte → mein Fehler.

## BLOCKING: Implementation Axiom

Vor jeder UI- oder Logik-Implementation:
1. Statamic-Komponente prüfen (`vendor/statamic/cms/resources/js/components/ui/index.js`) — verfügbare: `Tabs`/`TabList`/`TabTrigger`/`TabContent`, `Dropdown`/`DropdownItem`/`DropdownMenu`/`DropdownSeparator`, `Modal`/`ModalClose`/`ModalTitle`, `Stack`/`StackHeader`/`StackContent`/`StackFooter`, `Header`, `Card`, `Panel`, `Button`, `Badge`, `Popover`, `ConfirmationModal`, directive `v-tooltip`.
2. Browser-native HTML/CSS als zweite Wahl.
3. Custom nur wenn 1+2 nicht passen — explizit flaggen.

Imports: `import { ... } from '@statamic/cms/ui'` und `import { Link, Head, router } from '@statamic/cms/inertia'`.

## BLOCKING: Blast-Radius vor Code-Änderung

Vor jedem `Edit` auf `src/`:
1. grep nach allen Aufrufern und allen Vorkommen des zu ändernden Patterns
2. Tabelle posten welche Stellen betroffen sind und wie der Fix dort wirkt
3. erst dann edit

Wenn User fragt "andere Stellen?" → ich war zu schmal.

## BLOCKING: Build verify nach jedem Frontend-Edit

Nach jedem `.vue`/`.js` edit Pflichtreihenfolge:
1. `PATH=/Users/rossbach/.nvm/versions/node/v22.22.2/bin:$PATH npm run build 2>&1 | tail -15` (NICHT -3 — Build-Errors stehen oben)
2. Auf "✓ built in Xms" prüfen. Bei "Build failed" → STOP, fix syntax, retry.
3. `cd ~/Herd/prose-peak-test && php artisan vendor:publish --provider="Arturrossbach\Linkwise\ServiceProvider" --force`
4. Manifest-Hash neu + `grep -oE "<unique-changed-string>" public/vendor/linkwise/build/assets/addon-<NEW>.js` → Änderung muss im Bundle sein
5. erst dann "kann testen"

## BLOCKING: Single Source of Truth

Bei Daten-Flow-Änderungen erst tracen wo der Wert herkommt + wo er angezeigt wird. Wenn an mehreren Stellen berechnet → eine ist authoritative, andere müssen sie nutzen. Heutige Sources of Truth:

- **Suggestion counts**: `InboundEngine::suggest()` ist autoritativ. `DashboardController` ruft sie pro Entry und überschreibt `LinkReport::suggestionCounts()`. Keine duplizierte Suggestion-Logik in LinkReport.
- **Outbound counts**: `LinkReport` (internal). `DashboardController` addiert external für `outbound_total`.
- **Broken links**: `BrokenLinkChecker` schreibt JSON. `BrokenLinkReport` + Overview lesen daraus.
- **Domain attributes**: `DomainReport` scannt. `domain-attributes.json` ist Storage. `LinkwiseLinkMark` liest fürs Rendering.
- **Auto-link preview**: `AutoLinkApplier::applyRule(preview: true)`.
- **Bulk completion**: alle 5 commands schreiben `phase=done` mit `heartbeat` at root. `JobLock::snapshot()` pickt latest by heartbeat. Frontend `LinkwiseLayout` rendert toast + persistent banner.

## Bulk-Write-Path Standard

Jeder neue/geänderte bulk command MUSS:
1. **`JobLock::registerCrashGuard`** früh
2. **`SafeEntrySaver::verifyHashes`** per-record (in der loop) — NICHT fail-fast 409 im Controller
3. **`BulkSnapshotStore::record` → `appendWrittenItem` per success → `recordPostHashesForEntries` → `markCompleted`**
4. **`recordBulkSkipped`** im skip-pfad (anchor not found, hash conflict, throwable) für drawer-Sichtbarkeit
5. **`'heartbeat' => time()` at ROOT** des `phase=done` cache (für frontend dedup + JobLock-snapshot-priority)
6. **per-item try/catch** im loop → keine ganze-bulk-aborts

Modelle: `LinkInsertCommand`, `DetailUnlinkCommand`, `UrlChangerApplyCommand`. Ausnahme: `BulkUnlinkCommand` fehlt noch verifyHashes (offene Tech-Debt).

## Code Quality

- DRY ohne premature abstraction
- Type hints auf jeder PHP-Methode
- ProseMirror nur via Bard-API
- Stemming via `wamania/php-stemmer` (Snowball)
- Keine SVG width/height attributes — CSS

## Workflow

- NIE mehrere Steps vor User-Browser-Test
- Jeder Step endet mit konkreter Test-Anweisung
- CLI-Verify (tinker/phpunit) bevor User UI testet
- Auto-link Tests: word-boundary, once-per-post, already-linked, self-reference, preview-consistency
- Volle Playwright-Suite NIE während Dev — nur `--grep` targeted
- Vor `git commit` volle PHPUnit Unit-Suite grün
