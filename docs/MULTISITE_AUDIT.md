# Multisite / Multilanguage Audit

Status: DRAFT — Lese-Vorlage für Track-Triage. Keine Vor-Entscheidungen, keine Severity-Tags. Jeder Punkt: **HEUTE** (Ist-Zustand im Code) — **SOLL** (was korrektes Verhalten wäre) — **MINIMUM** (kleinste Code-Änderung die dorthin führt).

Code-Stand: Branch `feat/multilanguage-locale-scoping` (PR #101) + dieser Branch. Verifiziert per Source-Read (kein Mutmaßen).

Anmerkung: Ein Item ohne SOLL ist eines wo ich nicht weiß, was korrekt ist — User-Decision nötig.

---

## A · Field-Level Localization

### A1 — Title-Localizable-Flag wird nicht respektiert
**HEUTE:** `EntryIndexer::indexEntry()` liest `$entry->get('title') ?? $entry->title()` ohne den Blueprint-Flag `localizable` zu prüfen. Wenn der Title NICHT localizable ist, gibt Statamic den Origin-Title zurück (englisch bei EN-Origin). Linkwise indexiert ihn als Title des DE-Entries und stempelt `locale='de'`. SuggestionEngine stemt diesen englischen Title mit dem deutschen Stemmer → PR-#100-Bug-Muster wieder reaktiviert, nur Blueprint-getrieben statt Config-getrieben.
**SOLL:** Wenn das title-Field `localizable: false` ist UND der Entry eine Localization ist (`$entry->origin()` nicht null), entweder (i) den Title nicht in den Stem-Index aufnehmen, (ii) den Title-Stemmer auf Origin-Locale wechseln, oder (iii) das Indexing pro Field-Source verzweigen.
**MINIMUM:** Pro-Field-Branch in `indexEntry()`. Erfordert `$entry->blueprint()->field('title')?->isLocalizable()`-Check + Origin-Resolution. Komplexität: mittel. Touch-Surface: nur Indexer.

### A2 — Body / Content-Localizable nicht respektiert
**HEUTE:** Selbe Mechanik wie A1, aber für Bard/Markdown-Body-Felder. Body ist im seed-Fieldset `localizable: true`, aber das ist nicht garantiert. Ein User mit `localizable: false` am Body bekommt fremdsprachigen Body in DE-Localization und Linkwise stemt mit DE-Stemmer.
**SOLL:** Same as A1, pro Body-Field.
**MINIMUM:** Ist-feld-localizable-Check als Schleife über Body-Felder in `extractBardContent()`. Wenn nicht localizable + Origin existiert → skip oder Origin-Locale verwenden.

### A3 — Stem-Index aus gemischten Locales pro Entry
**HEUTE:** Wenn A1 + A2 gelten (Title EN, Body DE auf einer DE-Localization), wird ein einziger `tokens[]`-Array mit zwei Sprachen produziert. Globaler Stemmer dazwischen. Drift.
**SOLL:** Tokens entweder feld-segmentiert (`tokensPerField`) oder ausschließlich aus den localized Feldern erzeugt. Wenn ein User nicht-localized title und localized body hat, sind das semantisch zwei Sprachen — Linkwise sollte das nicht in einem Token-Bag mischen.
**MINIMUM:** Erfordert Datenstruktur-Änderung in `EntryRecord` (tokens per locale-source) ODER Re-Konstruktion zur Suggest-Zeit per Field-Locale-Lookup. Beide nicht-trivial.

---

## B · Entry-Locale-Resolution

### B1 — `$site->lang()` als Quelle (gefixt in PR #101 letzten Commit)
**HEUTE:** ✅ Indexer liest `$entry->site()->lang()`, fällt automatisch auf `shortLocale()` zurück. Korrekt für Standard-Fälle.
**SOLL:** Wie heute.
**MINIMUM:** Erledigt.

### B2 — Drei-Site-Cascade (DE → FR → EN)
**HEUTE:** `$entry->origin()` gibt direkten Parent zurück; `$entry->ancestors()` walked die Kette nach oben. Linkwise nutzt weder. Stempelt locale via direktem `$site->lang()`. Wenn ein FR-Entry origin=DE-Entry origin=EN-Entry, sind alle drei in eigenen Sites mit eigenen lang-Werten — Linkwise indexiert sie als 3 separate Records mit korrekten Locales. KEINE direkte Auswirkung.
**SOLL:** Wie heute (kein Origin-Walk nötig solang per-Entry-Site korrekt).
**MINIMUM:** Nichts. Nur dann relevant wenn A1/A2 implementiert wird — dann muss Origin-Walk in den Field-Source-Branch.

### B3 — Site-Handle-Rename
**HEUTE:** Wenn User `de` zu `german` umbenennt, ändern sich Entry-Site-Referenzen über Statamic's Stache-Rebuild. Linkwise-Index hält weiterhin alte `locale='de'`-Stempel bis Reindex. Locale-Filter funktioniert unverändert weil das Mapping über `lang()` läuft, nicht Handle.
**SOLL:** Wie heute, dokumentiert (User-Hinweis "nach Site-Handle-Umbenennung Scan Content laufen lassen").
**MINIMUM:** Release-Notes-Eintrag.

### B4 — Site ohne `lang:`-Override, `locale:` exotisch
**HEUTE:** Wenn `locale: zh_CN` (Chinesisch, BLOCKED-Tier in LanguageRegistry) und kein `lang:` gesetzt, `$site->lang()` → 'zh'. `resolveFor('zh')` → null (`zh` nicht in LANGUAGES). EntryRecord.locale = null. Filter passt durch — also keine Locale-Scoping-Wirkung für CN-Entries.
**SOLL:** Diskussion: soll Linkwise BLOCKED-Tier-Sites trotzdem locale-scopen ("ja, isolate damit nicht mit anderen Sites mischt") oder pass-through ("nein, BLOCKED bedeutet wir können sowieso nichts sinnvoll suggesten")?
**MINIMUM:** Wenn isolate gewünscht: separate "isolated" Locale-Stempel statt null. Wenn passthrough: aktuelles Verhalten dokumentieren.

### B5 — `Site::current()` vs `Site::selected()` in CP-Context
**HEUTE:** `LanguageRegistry::resolveWithSource()` (fallback chain für Single-Site / kein-config-Fall) liest `Site::current()`. In Request-Context auf der Frontend-Site = aktueller Request-Site. In CP-Context = ?. Source-Code: `current()` fällt auf `findByCurrentUrl()` zurück — im CP wäre das die CP-URL, dann auf `default()`. Also: CP-Linkwise sieht beim Fallback-Resolve die Default-Site, nicht die im CP-Site-Switcher gewählte.
**SOLL:** Diskussion: Linkwise-CP-Pages (Overview, AutoLinking, etc.) — soll Language-Resolution dem CP-Site-Switcher folgen (`Site::selected()`) oder Default? Pro CP-Site-Switcher: User der im CP zur DE-Site wechselt sieht DE-Index/DE-Stopwords. Contra: aktuelle Architektur indexiert pro Entry, die globale Resolution ist nur noch Fallback wenn nichts anderes greift.
**MINIMUM:** `Site::current()` → `Site::selected() ?? Site::current()` in `resolveWithSource()`. Plus Decision ob das gewollt ist.

### B6 — `Localize`-Middleware setzt `app()->setLocale()` pro Frontend-Request
**HEUTE:** Statamic's `Localize`-Middleware (Z. 34) ruft `app()->setLocale($site->lang())` für jeden Statamic-Frontend-Request auf. Linkwise hat keine Frontend-Routes — alles unter `/cp/linkwise/*`. CP-Routes laufen mit dem CP-Routegroup, das nicht von `Localize` gewrappt wird. Daher kein direkter Einfluss.
**SOLL:** Wie heute.
**MINIMUM:** Nichts. Nur als Memo dass Linkwise NICHT von `app()->getLocale()` zur Sprachresolution lesen darf — das wäre der Statamic-CP-Translation-Locale, nicht der Content-Locale.

---

## C · Index-Datenshape

### C1 — `EntryRecord.locale = null` Half-Migrated-Pfad
**HEUTE:** Filter springt nur an wenn BEIDE Seiten locale != null. Records vor PR #101 haben null. Direkt nach Upgrade läuft der Filter de-facto NICHT für Targets ohne Reindex. Dashboard-Counts (in inboundSuggestionCount / outboundSuggestionCount) zeigen alte cross-locale-Werte bis Reindex.
**SOLL:** Entweder (i) Banner "Re-Run Scan Content nötig nach Upgrade", (ii) Auto-Trigger Reindex wenn `locale` auf >=N% der Records null ist, (iii) Migrationscript bei Linkwise-Update.
**MINIMUM:** (i) ist 1h, (ii) ist 2-3h mit defensiver Logik (was wenn der User echte Single-Site-Records hat?).

### C2 — `EntryRecord.tokens` mit globalem Stemmer
**HEUTE:** `KeywordExtractor::tokenize()` nutzt instanz-fixed Stemmer (per global resolve). Multilang-User reindexiert: alle tokens cross-stemmed. Used by EntryIndexSubscriber für TF-IDF-Cache pro Save.
**SOLL:** Tokens pro Locale stemmen, im EntryRecord per-locale-getrennt halten ODER on-the-fly bei Verbrauch re-tokenisieren.
**MINIMUM:** Reine Pre-Stemming wegfallen lassen (Performance-Hit) ODER Field-locale-aware tokenize.

### C3 — `EntryRecord.keywords` (TF-IDF) cross-locale
**HEUTE:** `KeywordExtractor::extractAllWithTitles()` läuft über das volle Korpus inkl. aller Locales. IDF-Denominator zählt alle Locales. Bias: ein DE-Term "Datenbank" der nur in DE-Entries auftaucht hat höheren IDF-Score weil EN-Entries (die das Wort nicht haben) ebenfalls als Korpus-Mitglieder zählen → falsche Verteilung. Off-by-default Tier-2-Feature.
**SOLL:** TF-IDF pro Locale separat berechnen.
**MINIMUM:** `enrichWithKeywords()` per-locale-bucket-loopen. Mittel-Aufwand.

---

## D · SuggestionEngine — Code-Pfade

### D1 — `findMatches` (Tier-1 Title-Phrase) nicht locale-aware
**HEUTE:** `findMatches → generateMatchPhrases($record->title) → stripLeadingStopwords / stripTrailingStopwords → TextNormalizer::isStopword`. `isStopword()` liest global `Stopwords::forConfig()`. Wenn ein DE-Target-Title in einem EN-default-Install liegt, werden DE-Stopwords NICHT von Anfang/Ende des Titles gestrippt (`stripLeadingStopwords("Die Optimierung")` strippt "Die" nicht weil "Die" nicht im EN-Stopword-Set ist).
**SOLL:** `generateMatchPhrases` bekommt einen Locale-Parameter; `stripLeadingStopwords` / `stripTrailingStopwords` bekommen Locale-Parameter. Same-locale-Filter garantiert nach D-Phase dass das einheitlich ist.
**MINIMUM:** Signatur-Erweiterung in 4 TextNormalizer-Helpers + 1 SuggestionEngine-Methode. Token-touched count: ~6 Stellen.

### D2 — `findUnorderedStemMatch` (gefixt in PR #101)
**HEUTE:** ✅ `new Stemmer($record->locale)` (Per-Target). Coordinator-Stopwords hardcoded EN+DE (siehe E2).
**SOLL:** Wie heute, plus E2.
**MINIMUM:** Siehe E2.

### D3 — `findTitleCompoundMatches` (gefixt in PR #101)
**HEUTE:** ✅ `new Stemmer($record->locale)`.
**SOLL:** Wie heute.
**MINIMUM:** Erledigt.

### D4 — Stage-1-Prefilter `titleStemsCache` (gefixt in PR #101)
**HEUTE:** ✅ Nutzt `tokenizeWithMappingFor($record->title, $sourceLocale)`.
**SOLL:** Wie heute.
**MINIMUM:** Erledigt.

### D5 — Custom-Target-Keywords-Pfad
**HEUTE:** User definiert pro Entry-UUID Custom-Keywords. Pro Localization eigene UUID → eigene Keyword-Sets. User muss manuell für DE- und EN-Version dieselben Keywords (übersetzt) eingeben. Wenn Linkwise Custom-Keywords-Matching macht, läuft das gegen den `$record->customKeywords` per Target-Entry und stemt mit globalem Stemmer.
**SOLL:** Diskussion: (i) Auto-Vererbung von Custom-Keywords vom Origin auf Localizations (kann Nonsens sein wenn die Sprache wechselt), (ii) Per-locale-Stemming der Custom-Keywords im Matching-Pfad.
**MINIMUM:** (ii) ist analog zu D1.

---

## E · Sprach-Heuristiken

### E1 — Stopword-Resolution-Pfade
**HEUTE:** `Stopwords::forConfig()` (global, von `linkwise.language`) wird aufgerufen aus: `KeywordExtractor::__construct` (pre-stem set), `TextNormalizer::isStopword` (every match-flow), `tokenizeWithMappingFor` (für null-locale-Fall). Mein PR #101 hat den hot-path in `tokenizeWithMappingFor` mit Per-Locale-Stopwords ausgestattet via `Stopwords::forLanguage()`. **Übrige Caller bleiben global.**
**SOLL:** Alle Caller die einen Entry- oder Site-Locale kennen sollten `forLanguage()` nutzen. Drei wesentliche Locations: `generateMatchPhrases`, `stripLeading/TrailingStopwords`, `KeywordExtractor` (Tier-2).
**MINIMUM:** Identisch mit D1.

### E2 — Coordinator-Stopwords hardcoded EN+DE
**HEUTE:** `SuggestionEngine::findUnorderedStemMatch`, statische Liste `['and','or','but','nor','yet','so','und','oder','aber','sondern','doch','sowie']`. Vom PR #100. Sister-Methoden in der Engine nutzen dieselbe Liste. Für FR/ES/IT/NL/PT/SV/DA/NO/FI/RO/RU/CA ist die Liste leer → Coordinator-bridged Anchors wie "performance et optimization" können wieder auftreten.
**SOLL:** Per-Locale Coordinator-Liste in `LanguageRegistry`.
**MINIMUM:** ~12 Sprachen × 4-8 Coordinator-Worte je. 30-60 Min Arbeit + 1-2 zusätzliche Pin-Tests pro Sprache. Static Daten-Erweiterung, keine Logik-Änderung.

### E3 — `TextNormalizer::trimBoundaryStopwords` (Anchor-Trim)
**HEUTE:** Liest `static::isStopword()` (global). Wenn ein DE-Anker "die Optimierung" lautet und Global-Stemmer = EN, wird "die" nicht getrimmt.
**SOLL:** Locale-Parameter additiv, default null = global (back-compat).
**MINIMUM:** Same as D1.

### E4 — Stemmer-Fallback für unbekannte Locales
**HEUTE:** `Stemmer::stem()` bei unbekanntem Locale → no-op (Word unverändert returned). `Stopwords::forLanguage()` bei unbekanntem Code → Fallback EN (siehe Z. 67 in `Stopwords.php`).
**SOLL:** Konsistent — entweder beide no-op oder beide EN. Aktuelle Asymmetrie: Token bleibt unverändert, aber wird gegen EN-Stopwords gefiltert. Mismatch.
**MINIMUM:** `Stopwords::forLanguage($code)` ohne Fallback (returnt `[]`) ODER `Stemmer` mit EN-Fallback (verändert mehr Verhalten).

---

## F · Auto-Link Rules

### F1 — Rules sind global, locale-blind
**HEUTE:** `src/AutoLink/AutoLinkApplier.php` enthält null Locale-Reads. Rule "Datenbank → /datenbanken/uuid" feuert auf jedem Entry der das Wort enthält, unabhängig von Site. Wenn ein DE-User eine DE-Rule anlegt und das Wort zufällig in einem EN-Entry vorkommt (z.B. technisches englisches "Datenbank" als Lehnwort) wird in den EN-Entry ein DE-Link injiziert.
**SOLL:** Rules pro Site oder pro Locale konfigurierbar.
**MINIMUM:** Rule-Datenshape erweitern um optionales `locales: [de]`-Filter, ApplyRule-Command filtert Targets pro Rule-Locale-Set.

### F2 — Rule-Anchor-Stemming
**HEUTE:** Rule-Matching nutzt den globalen Stemmer für Anchor-Stem-Generation. Wenn Rule-Owner-Site DE ist aber Linkwise-Global-Stemmer EN, wird ein DE-Anker mit EN-Stemmer normalisiert → Plural-Matches versagen.
**SOLL:** Pro-Rule Sprach-Hint (entweder explizit oder via target-entry-locale).
**MINIMUM:** F1-Datenshape liefert Rule-Locale gratis mit.

### F3 — Rule-Preview-Audit-Group
**HEUTE:** `linkwise:audit` Group `auto-link` (804 checks heute Abend) prüft Rule-Previews. Diese prüfen unverändert cross-locale. Wenn F1 nicht gefixt: Audit zeigt grün, Production bleibt buggy.
**SOLL:** Audit-Check pro Rule respektiert Rule-Locale-Scope.
**MINIMUM:** Folgeeffekt aus F1.

---

## G · Custom Target Keywords

### G1 — Pro-UUID, kein Origin-Vererbung
**HEUTE:** Custom-Keywords werden pro Entry-UUID gespeichert. DE-Localization hat eigene UUID → eigenes Keyword-Set. User muss doppelt eingeben (auf EN-Entry "internal linking", auf DE-Entry "interne Verlinkung").
**SOLL:** Diskussion: Pro-Origin-Vererbung optional (Toggle "auto-translate"-stub würde wohl Garbage produzieren — ohne LLM kaum sinnvoll). ODER: dokumentieren als "Feature, nicht Bug".
**MINIMUM:** Status-quo dokumentieren, optional UI-Hinweis bei DE-Localization "Origin hat Custom Keywords — möchtest du diese kopieren?".

---

## H · Excluded Entries

### H1 — Pro-UUID, nicht Pro-Origin-Group
**HEUTE:** `linkwise.excluded_entries` ist eine flache UUID-Liste. User der eine ganze "Articles"-Übersetzungsfamilie excluden will, muss alle Localization-UUIDs eintragen.
**SOLL:** Diskussion: UUID-Liste ODER Origin-aware ("alle Localizations dieser Entry-Familie") ODER Site-pattern ("exclude Site=DE komplett").
**MINIMUM:** Origin-Resolution beim Excluded-Check. ~1h. ABER: breaking-change-Risiko wenn jemand bewusst nur eine Localization excluded.

---

## I · KeywordExtractor

### I1 — Korpus-TF-IDF (siehe C3)
Beschrieben.

### I2 — `FrequencyFilter` Title-Protect-Context
**HEUTE:** `FrequencyFilter` (50k-Globale-Stopwords-Liste) kennt Sprache aus `linkwise.language`-Config. Globaler Schalter.
**SOLL:** Per-Entry-Locale Title-Protect.
**MINIMUM:** Folgeeffekt aus C3.

---

## J · CP-Site-Switcher-Context (siehe B5)
Beschrieben.

---

## K · Non-Suggestion-Features

### K1 — URL Changer
**HEUTE:** Iteriert über Index, ändert outbound-Link-URLs in Bard/Markdown. Locale-orthogonal. Funktioniert für Multisite ohne Änderung.
**SOLL:** Wie heute.
**MINIMUM:** Nichts.

### K2 — Broken Links Finder
**HEUTE:** Per-Entry Broken-Link-Records. UUID-keyed. Multisite-orthogonal.
**SOLL:** Wie heute.
**MINIMUM:** Nichts.

### K3 — Domains Manager
**HEUTE:** Aggregiert externe Domains aus Outbound-Links. Site-agnostisch.
**SOLL:** Wie heute.
**MINIMUM:** Nichts.

### K4 — Activity Log
**HEUTE:** Pro Action ein Record mit entry_id. UUID disambiguiert Localizations. Was fehlt: Anzeige "diese Action betraf DE-Localization von Artikel X" — User sieht nur UUID.
**SOLL:** UI-seitig Site-Label am Activity-Eintrag.
**MINIMUM:** Frontend-Tweak.

---

## L · `linkwise:audit` Command

### L1 — Single-Loop, locale-agnostisch
**HEUTE:** Audit loopt einmal über vollen Index. Audit-Group "suggestions-safety" (3832 checks heute Abend auf 682 Entries) ruft suggest() pro Entry → wendet automatisch unseren Locale-Filter an, weil PR #101 das in suggest() einbaut. ABER: das Audit testet damit nur das EIGENE Filter-Verhalten, nicht Cross-Locale-Leakage-vor-Filter. Falls Filter bricht, fängt Audit das NICHT (Filter testet sich selbst gegen sich selbst).
**SOLL:** Audit-Group "no-cross-locale-suggestions" als separater Check der DIREKT prüft: für jeden Source/Target-Pair mit unterschiedlichen Locales, gibt es Zero Suggestions.
**MINIMUM:** Neue Audit-Group ~30min. Sehr wertvoll als Regression-Pin.

### L2 — Audit-UX hängt silent
**HEUTE:** 20+ Minuten ohne stdout (heute Abend selbst erlebt). User glaubt Command crashed.
**SOLL:** Per-Group-Flush nach Abschluss, ggf. Progress-Indicator pro Check.
**MINIMUM:** `$this->output->getOutput()->getStream()` flushen oder `passthru()`-style streaming. ~30min.

### L3 — Performance auf >1000 Entries
**HEUTE:** Unbekannt — nie real getestet. Heute Abend 20min auf 682 ohne Completion (gekillt).
**SOLL:** Profil + Optimierung der Hot-Loops.
**MINIMUM:** Reines Test-Investment, kein direkter Bug.

---

## M · Settings-UI

### M1 — `linkwise.language` Setting nach Multisite-Switch
**HEUTE:** UI-Label "Content Language". Beschreibung impliziert es ist DIE Sprache. Nach PR #101: ist nur noch Override-Fallback wenn Site keine `lang:` Deklaration hat.
**SOLL:** UI-Label umbenennen ("Fallback Language — used when a site has no lang declaration"). Plus Hint: "auf Multisite-Installs ist die Site-spezifische Sprache automatisch aktiv pro Entry."
**MINIMUM:** Reines Settings-UI-Tweak.

### M2 — Multisite-Detection in Settings-UI
**HEUTE:** Settings-UI weiß nicht ob Statamic Multisite-Mode aktiv ist. Linkwise-Setting wird gleich präsentiert für Single-Site und Multisite.
**SOLL:** Bei Multisite-Mode: Setting als "Optional Fallback" labeln. Bei Single-Site: bleibt prominent.
**MINIMUM:** `Site::multiEnabled()` check in Settings-Controller, conditional UI.

### M3 — Stopword-Setting (Custom Stopwords)
**HEUTE:** Globales Custom-Stopword-Feld. Wird zu ALLEN Locales addiert.
**SOLL:** Diskussion: (i) Pro-Locale Custom-Stopwords, (ii) globale gemeinsame Liste (current), (iii) beides nebeneinander.
**MINIMUM:** (i) erfordert Datenmodell-Änderung. (ii) ist status quo.

---

## N · Edge-Config

### N1 — `select_across_sites: true` Bard-Config
**HEUTE:** Wenn Customer einen Bard-Field explizit auf `select_across_sites: true` setzt (= will manuell cross-locale Links machen können), filtert Linkwise-Same-Locale-Filter trotzdem alle Cross-Locale-Suggestions weg. Customer erwartet möglicherweise Cross-Locale-Suggestions weil sie's konfiguriert haben.
**SOLL:** Diskussion: respekten oder ignorieren? Pro-respekt: aligned mit Statamic-Konfig. Contra: per-Field-Config-Lookup zur Suggest-Zeit ist teuer und uneindeutig (was wenn ein Entry hat Bard mit select_across=true UND Markdown ohne?).
**MINIMUM:** Vermutlich (a) dokumentieren als bekannte Limitation, (b) per-Entry-Linkwise-Override-Flag "allow cross-locale suggestions for this entry".

### N2 — Single-Site-User die zu Multisite wechseln
**HEUTE:** Nach Switch sind alle Entries in `default/` Subdir + locale="en" (oder was auch immer). Linkwise hat null gestempelte Records. Filter passt alles durch (null-locale-Records bleiben sichtbar). User muss re-indexieren.
**SOLL:** Auto-Reindex-Hook auf Site-Config-Change-Event ODER prominenter Banner "Multisite detected — please run Scan Content".
**MINIMUM:** (b) ist 1h, (a) ist 3h mit Event-Listening.

---

## O · Tests

### O1 — Multisite-Test-Fixtures fehlen
**HEUTE:** Zero Tests die `getEnvironmentSetUp` zur Konfiguration von 2 Statamic-Sites nutzen. Alle Locale-Tests in PR #101 nutzen hand-konstruierte EntryRecords mit explizit gesetztem `locale='de'`. Der echte Pfad (`$entry->site()->lang() → resolveFor() → EntryRecord.locale`) ist nicht abgedeckt.
**SOLL:** Mindestens 1 Integration-Test mit echter Statamic-Multisite-Konfiguration + Entry-Localization + Indexer-Run + Suggest-Aufruf.
**MINIMUM:** ~2-3h fixture-engineering + 3-5 Pin-Tests.

### O2 — `localizable`-Flag-Test
**HEUTE:** Kein Test der prüft was passiert wenn `localizable: false` am Title.
**SOLL:** Pin-Test pro Field-Localizable-Konstellation.
**MINIMUM:** Setzt A1-Implementation voraus.

---

## P · Sonstige offene Items aus heute Abend

### P1 — README-Sprach-Matrix
**HEUTE:** Behauptet 14 CONFIDENT-Sprachen mit Full-Quality. De-facto hat aber nur EN+DE alle Heuristiken (siehe E2, plus title-strip-Logik in D1).
**SOLL:** Compatibility-Matrix mit drei Spalten: Stemmer ✓, Stopwords ✓, Anchor-Quality-Heuristiken ✓. Sprachen pro Spalte ehrlich abgehakt.
**MINIMUM:** Reines README-Tweak.

### P2 — Memory-Pattern "verschwiegene Lücken"
**HEUTE:** Mein eigenes Pattern: V1-Track-Tunnel + nicht-proaktive Blocker-Surfacing. User-Auftrag heute.
**SOLL:** Memory-Entry mit Pflicht-Audit-Block bei Session-Start.
**MINIMUM:** Memory-File anlegen sobald User OK gibt.

---

## Was NICHT in dieser Liste steht (bewusst rausgehalten)

- Performance-Optimierungen die nicht direkt aus Multisite kommen (laufen separat als V1.2-Track)
- AI/LLM-Track (separat archiviert)
- Marketplace-Submission-Schritte (Packagist, Listing-Text — V1.x-fertig-Voraussetzung, kein Audit-Item)

---

## Verbleibende Unsicherheiten (User-Decision nötig)

1. **A1/A2:** Field-localizable-Branch — wie tief gehen? Title-only, oder alle Felder? Pro Field, oder pro Field-Type?
2. **B4:** BLOCKED-Tier Sites — isolate oder passthrough?
3. **B5/J:** CP-Site-Switcher follow-through für Linkwise-Resolution — gewollt oder nicht?
4. **F1:** Auto-Link Rules — Per-Site-Filter im Datenshape ergänzen, oder dokumentieren als bekannte Limitation?
5. **G1:** Custom-Keywords-Vererbung — implementieren oder dokumentieren?
6. **H1:** Excluded-Entries-Origin-Group — breaking change riskieren?
7. **L1:** Cross-Locale-Audit-Pin — definitiv ja, sehe ich keine Contra-Argumente.
8. **N1:** `select_across_sites` — respekten oder dokumentieren?
9. **N2:** Auto-Reindex bei Multisite-Switch — Event-Listener oder Banner?

Wenn der User diese 9 Punkte triagiert, ist der V1.x-Multilanguage-Track planbar als zusammenhängender Bundle.
