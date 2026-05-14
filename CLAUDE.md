# Linkwise — Claude Code Rules

## BLOCKING: Pre-Flight before "kannst testen"

Bevor du dem User irgendwas zum Testen gibst, MUSS jeder Punkt erfüllt sein:

1. **Domain-First Brainstorm**: aus User-Sicht ("was wäre dem User schlimm?"), 5+ Failure-Modi pro Aktion. Keine Code-Begriffe. Mapping pro Punkt zu Unit-Test ODER Audit-Check ODER explizit als bekanntes Risiko.
2. **Tests laufen** — alle grün, neue + Regression.
3. **`php artisan linkwise:audit`** gegen `~/Herd/prose-peak-test`. Exit 0 oder jedes pre-existing failure explizit benannt.
4. **Real-flow proof** für mutating changes (tinker oder Playwright, kein "es sollte funktionieren").

Test-Verantwortung liegt bei Claude, nicht beim User. Findet der User einen funktionalen Bug den ein Test/Audit gefangen hätte → mein Fehler.

## BLOCKING (Frontend-only): Implementation Axiom

Greift nur bei `.vue`/`.js`-Touches. Bei reinen PHP/Backend-PRs überspringen.

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
1. `source ~/.nvm/nvm.sh && nvm use 22 && npm run build 2>&1 | tail -15` (NICHT -3 — Build-Errors stehen oben)
2. Auf "✓ built in Xms" prüfen. Bei "Build failed" → STOP, fix syntax, retry.
3. `cd ~/Herd/prose-peak-test && php artisan vendor:publish --provider="Arturrossbach\Linkwise\ServiceProvider" --force`
4. Manifest-Hash neu + `grep -oE "<unique-changed-string>" public/vendor/linkwise/build/assets/addon-<NEW>.js` → Änderung muss im Bundle sein
5. erst dann "kann testen"

## BLOCKING: Single Source of Truth

Bei Daten-Flow-Änderungen erst tracen wo der Wert herkommt + wo er angezeigt wird. Wenn an mehreren Stellen berechnet → eine ist authoritative, andere müssen sie nutzen.

Heutige SoTs (code-volatil, gepflegt): siehe Memory `sot_index.md`.

## Bulk-Write-Path Standard

Jeder neue/geänderte bulk command MUSS 6 Punkte erfüllen (CrashGuard / per-record verifyHashes / Snapshot-Store-Quartett / recordBulkSkipped / heartbeat-at-root / per-item try-catch). Vollständige Liste + Modelle: Memory `bulk_write_path_standard.md`.

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
