<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Multilingual seed for V1.x locale-scoping smoke tests.
 *
 * Creates per-locale entries on the matching Statamic site so the
 * multilanguage suggestion-filter can be exercised in a real browser.
 * Pools are MONOLINGUAL per language (no auto-translation — would just
 * be garbage); each language gets its own topical articles fully written
 * in that language. That's enough to:
 *  - Verify the same-locale filter (DE source shouldn't suggest EN target)
 *  - Verify per-locale stemmer (DE source DE target stems correctly)
 *  - Verify per-locale coordinator-stopwords (PR #102 audit E2)
 *
 * Auto-detects which sites are configured. If a site with `lang: de`
 * doesn't exist, the DE pool is skipped with a warning. Sites without a
 * resolved ISO mapping are also skipped. Single-site installs effectively
 * get only the matching pool.
 */
class SeedMultilingualCommand extends Command
{
    protected $signature = 'linkwise:seed-multilingual
                            {count=10 : Entries per available language}
                            {--collection=articles : Collection handle}
                            {--locales=en,de,nl : Comma-separated ISO codes to seed (defaults to all 3 covered pools)}';

    protected $description = 'Seed monolingual EN/DE/NL articles per Statamic site for V1.x multilanguage smoke testing';

    public function handle(): int
    {
        $count = (int) $this->argument('count');
        $collectionHandle = $this->option('collection');
        $requestedLocales = array_map('trim', explode(',', (string) $this->option('locales')));

        $collection = Collection::findByHandle($collectionHandle);
        if (! $collection) {
            $this->error("Collection '{$collectionHandle}' does not exist. Create it first or pass --collection=<handle>.");
            return self::FAILURE;
        }

        // Map each Statamic site to its ISO language. A site is only
        // seedable if its lang() resolves to one of our pools.
        $sitesByLang = [];
        foreach (Site::all() as $site) {
            $iso = $this->isoFor($site);
            if ($iso !== null && in_array($iso, $requestedLocales, true)) {
                $sitesByLang[$iso] = $site->handle();
            }
        }

        if (empty($sitesByLang)) {
            $this->error('No Statamic sites match any of the requested locales ['.implode(',', $requestedLocales).']. Configure sites in resources/sites.yaml first.');
            return self::FAILURE;
        }

        $totalCreated = 0;
        foreach (['en', 'de', 'nl'] as $locale) {
            if (! isset($sitesByLang[$locale])) {
                if (in_array($locale, $requestedLocales, true)) {
                    $this->warn("Skipping {$locale}: no site with this language configured.");
                }
                continue;
            }

            $siteHandle = $sitesByLang[$locale];
            $pool = $this->poolFor($locale);

            // Cycle through the pool when count exceeds its size — matches
            // the SeedTestDataCommand behavior so large smoke datasets work.
            for ($i = 0; $i < $count; $i++) {
                $article = $pool[$i % count($pool)];
                $cycle = intdiv($i, count($pool));
                $title = $cycle === 0 ? $article['title'] : "{$article['title']} (Teil ".($cycle + 1).')';
                $slug = \Illuminate\Support\Str::slug($title);

                Entry::make()
                    ->collection($collectionHandle)
                    ->locale($siteHandle)
                    ->slug($slug.'-'.$locale)
                    ->data(['title' => $title, 'content' => $article['content']])
                    ->save();

                $totalCreated++;
                $this->line("  [{$locale}] {$title}");
            }
        }

        $this->info("Created {$totalCreated} entries across ".count($sitesByLang)." sites.");
        $this->info('Now run: php artisan linkwise:rebuild-index (or click "Scan Content" in the CP).');

        return self::SUCCESS;
    }

    protected function isoFor($site): ?string
    {
        try {
            return \Arturrossbach\Linkwise\NLP\LanguageRegistry::resolveFor(
                (string) ($site->lang() ?? '')
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{title: string, content: string}> */
    protected function poolFor(string $locale): array
    {
        return match ($locale) {
            'en' => $this->getArticlesEn(),
            'de' => $this->getArticlesDe(),
            'nl' => $this->getArticlesNl(),
        };
    }

    /** @return list<array{title: string, content: string}> */
    protected function getArticlesEn(): array
    {
        return [
            ['title' => 'Building Reliable APIs with Laravel', 'content' => "Laravel provides comprehensive tools for building production-grade REST APIs. Resource controllers handle CRUD operations while API Resources transform Eloquent models into consistent JSON responses.\n\nAuthentication is critical for any API. Laravel Sanctum offers lightweight token-based auth ideal for SPAs and mobile apps. For full OAuth2 support, Laravel Passport remains the gold standard.\n\nRate limiting and proper error handling round out a production API. Always version your endpoints from day one — adding versioning later is painful."],
            ['title' => 'Internal Linking Strategy for Better SEO', 'content' => "A strong internal linking strategy improves both SEO and user experience. Link from high-authority pages to important content to distribute link equity effectively. Use descriptive anchor text that tells users and search engines what the linked page covers.\n\nAim for three to five internal links per blog post. Avoid over-linking which dilutes link value and confuses readers. Regularly audit your internal links to find broken targets and orphaned content."],
            ['title' => 'Redis Caching Patterns for Web Applications', 'content' => "Redis serves as an in-memory data store ideal for caching, sessions, and real-time features. Cache-aside is the most common pattern: read from cache first, fall back to the database, then populate the cache.\n\nWrite-through caching keeps cache and database in sync at write time. Write-behind defers database writes for higher throughput at the cost of complexity. Choose the pattern that matches your consistency requirements."],
            ['title' => 'Database Index Design for Read-Heavy Workloads', 'content' => "Proper indexing is the difference between a query taking milliseconds versus seconds. Index columns used in WHERE clauses, JOIN conditions, and ORDER BY statements. Composite indexes follow the leftmost prefix rule.\n\nWatch out for over-indexing. Each index adds write overhead and storage cost. Measure actual query plans rather than guessing — most database engines provide an EXPLAIN command for this purpose."],
            ['title' => 'Vue Component Patterns and Composition', 'content' => "Vue's component model encapsulates template, logic, and styling. The Composition API introduced in Vue 3 provides better TypeScript inference and code organization compared to the Options API.\n\nProps flow down, events flow up. For complex state shared across many components, Pinia is the recommended store. Avoid prop-drilling by using provide/inject for tree-deep data."],
            ['title' => 'Statamic CMS Architecture Overview', 'content' => "Statamic is a flat-file CMS built on Laravel. Content lives in YAML and Markdown files rather than a database, which makes everything version-controllable. The Stache caches file metadata for fast lookups.\n\nBard provides a block-based editing experience. Bard content stores as ProseMirror JSON, enabling rich text with embedded components. The fieldtype system is extensible — addons can register custom fieldtypes."],
            ['title' => 'Docker Compose for Local PHP Development', 'content' => "Docker Compose orchestrates multi-container applications. A typical PHP setup includes containers for PHP-FPM, Nginx, MySQL or PostgreSQL, and Redis. Each service is defined in docker-compose.yml.\n\nVolume mounts share code between host and container so file changes are reflected immediately. Use named volumes for database storage to persist data across container rebuilds."],
            ['title' => 'CI/CD Pipeline Patterns for Laravel', 'content' => "Continuous integration runs your test suite on every push. GitHub Actions, GitLab CI, and CircleCI are all viable choices. The pipeline should run PHPUnit, code-style checks, and static analysis like PHPStan.\n\nContinuous deployment automates release to staging or production after CI passes. Laravel Forge and Envoyer streamline zero-downtime deployments for PHP applications."],
            ['title' => 'Sentry Error Monitoring for Production Apps', 'content' => "Error monitoring catches issues your tests missed. Sentry aggregates exceptions across all your services with full stack traces, breadcrumbs, and user context. The free tier covers small projects comfortably.\n\nIntegrate Sentry early in development. The Laravel SDK requires only a config entry and an environment variable. Configure release tracking so you can correlate spikes with deployments."],
            ['title' => 'Writing Maintainable PHPUnit Test Suites', 'content' => "A good test suite documents behavior, prevents regressions, and supports refactoring. Follow Arrange-Act-Assert. Each test should fail for one specific reason — fat tests with many assertions hide which behavior actually broke.\n\nFeature tests cover the HTTP layer; Unit tests cover individual classes in isolation. Use factories to build test data. Avoid sleeping in tests — use Carbon's freeze and travel instead."],
            ['title' => 'GraphQL Versus REST API Design', 'content' => "GraphQL and REST solve overlapping problems differently. REST uses resource-based URLs with HTTP methods; GraphQL exposes a single endpoint with a query language that lets clients request exactly the fields they need.\n\nGraphQL excels when multiple clients need different shapes of the same data. REST remains simpler for straightforward CRUD and benefits from HTTP caching out of the box."],
            ['title' => 'Server Security Hardening for Web Applications', 'content' => "Production web servers need defense in depth. Keep all software updated, enable HTTPS everywhere, and apply the principle of least privilege. Use a firewall and fail2ban to block brute-force attempts.\n\nApplication-level concerns include SQL injection, XSS, and CSRF. Laravel provides built-in protection via prepared statements, Blade escaping, and CSRF middleware. The OWASP Top 10 covers the most common vulnerability classes."],
        ];
    }

    /** @return list<array{title: string, content: string}> */
    protected function getArticlesDe(): array
    {
        return [
            ['title' => 'Datenbankoptimierung in der Praxis', 'content' => "Die Optimierung relationaler Datenbanken beginnt bei sinnvollen Indizes. Spalten in WHERE-Klauseln, JOIN-Bedingungen und ORDER-BY-Anweisungen profitieren am stärksten von Indizes. Zusammengesetzte Indizes folgen der Leftmost-Prefix-Regel.\n\nMessungen sind wichtiger als Annahmen. Jede Datenbank bietet einen EXPLAIN-Befehl, der den geplanten Ausführungsweg einer Abfrage zeigt. Ohne diese Daten ist Optimierung Glücksspiel.\n\nÜber-Indizierung kostet Speicher und verlangsamt Schreibzugriffe. Lösche Indizes, die keine Abfragen beschleunigen."],
            ['title' => 'Interne Verlinkung als SEO-Strategie', 'content' => "Eine durchdachte interne Verlinkung verbessert sowohl SEO als auch Nutzerführung. Verlinke von autoritären Seiten auf wichtige Inhalte, um Linkkraft sinnvoll zu verteilen. Beschreibender Ankertext sagt Nutzern und Suchmaschinen, worum es auf der Zielseite geht.\n\nDrei bis fünf interne Links pro Artikel sind ein guter Richtwert. Mehr verwässert die Linkkraft. Regelmäßige Audits decken kaputte Links und verwaiste Inhalte auf, die kein anderer Beitrag verlinkt."],
            ['title' => 'Redis als Caching-Schicht', 'content' => "Redis dient als In-Memory-Datenspeicher und eignet sich hervorragend für Caches, Sessions und Echtzeit-Features. Das Cache-Aside-Muster ist am verbreitetsten: zuerst Cache lesen, sonst Datenbank, dann Cache füllen.\n\nWrite-Through hält Cache und Datenbank synchron beim Schreiben. Write-Behind verzögert Datenbankschreibzugriffe für höheren Durchsatz auf Kosten der Komplexität. Wähle das Muster passend zu deinen Konsistenzanforderungen."],
            ['title' => 'API-Entwicklung mit Laravel', 'content' => "Laravel bietet umfassende Werkzeuge für produktionsreife REST-APIs. Resource Controller decken CRUD ab, API Resources verwandeln Eloquent-Modelle in konsistente JSON-Antworten.\n\nAuthentifizierung ist entscheidend. Laravel Sanctum eignet sich für leichtgewichtige Token-Auth bei SPAs und Mobile Apps. Für volles OAuth2 bleibt Laravel Passport die Referenz.\n\nRatenbegrenzung und durchdachte Fehlerbehandlung runden eine produktive API ab. Versioniere Endpunkte von Anfang an — Nachträgliches Hinzufügen ist schmerzhaft."],
            ['title' => 'Statamic für moderne Websites', 'content' => "Statamic ist ein Flat-File-CMS auf Basis von Laravel. Inhalte liegen in YAML- und Markdown-Dateien statt in einer Datenbank, was Versionskontrolle mit Git ermöglicht. Die Stache cached Datei-Metadaten für schnelle Lookups.\n\nBard bietet einen blockbasierten Editor. Bard-Inhalte werden als ProseMirror-JSON gespeichert, was reichhaltige Texte mit eingebetteten Komponenten erlaubt. Die Fieldtype-API ist offen — Addons registrieren eigene Feldtypen."],
            ['title' => 'Vue-Komponentenarchitektur', 'content' => "Vue kapselt Template, Logik und Stil pro Komponente. Die Composition API in Vue 3 bietet bessere TypeScript-Inferenz und Code-Organisation als die Options API.\n\nProps fließen nach unten, Events nach oben. Für komplexen geteilten Zustand zwischen vielen Komponenten ist Pinia der empfohlene Store. Vermeide Prop-Drilling durch provide/inject für tief verschachtelte Daten."],
            ['title' => 'Docker Compose für lokale Entwicklung', 'content' => "Docker Compose orchestriert mehrere Container als zusammenhängende Anwendung. Ein typisches PHP-Setup umfasst Container für PHP-FPM, Nginx, MySQL oder PostgreSQL und Redis. Jeder Service wird in docker-compose.yml definiert.\n\nVolume Mounts teilen Code zwischen Host und Container, sodass Dateiänderungen sofort sichtbar sind. Benannte Volumes persistieren Datenbankdateien über Container-Rebuilds hinweg."],
            ['title' => 'Continuous Integration für Laravel-Projekte', 'content' => "Continuous Integration führt deine Tests bei jedem Push aus. GitHub Actions, GitLab CI und CircleCI sind brauchbare Optionen. Die Pipeline sollte PHPUnit ausführen, Code-Style prüfen und statische Analyse wie PHPStan durchlaufen.\n\nContinuous Deployment automatisiert das Release in Staging oder Produktion nach erfolgreicher CI. Laravel Forge und Envoyer ermöglichen Zero-Downtime-Deployments für PHP-Anwendungen."],
            ['title' => 'Fehlermonitoring mit Sentry', 'content' => "Fehlermonitoring fängt Probleme, die deine Tests übersehen haben. Sentry aggregiert Exceptions mit vollständigen Stack-Traces, Breadcrumbs und Nutzerkontext. Der kostenlose Tarif reicht für kleine Projekte aus.\n\nIntegriere Sentry früh. Das Laravel-SDK braucht nur einen Config-Eintrag und eine Umgebungsvariable. Aktiviere Release-Tracking, damit du Fehlerspitzen mit Deployments korrelieren kannst."],
            ['title' => 'Wartbare PHPUnit-Tests schreiben', 'content' => "Eine gute Testsuite dokumentiert Verhalten, verhindert Regressionen und unterstützt Refactorings. Folge dem Arrange-Act-Assert-Muster. Jeder Test sollte aus einem konkreten Grund fehlschlagen — überfrachtete Tests verstecken, was tatsächlich kaputt ist.\n\nFeature Tests prüfen die HTTP-Schicht, Unit Tests einzelne Klassen isoliert. Factories bauen Testdaten konsistent. Vermeide echte Sleeps in Tests — nutze Carbons freeze und travel."],
        ];
    }

    /** @return list<array{title: string, content: string}> */
    protected function getArticlesNl(): array
    {
        // Each article cross-references at least 3 other titles from the
        // same pool so the title-stem-match path has work to do. Without
        // these references, monolingual within-language pools produce zero
        // suggestions even when the SAME topic is configured in three pools.
        return [
            ['title' => 'Database Optimalisatie in de Praktijk', 'content' => "Het optimaliseren van relationele databases begint bij zinvolle indexen. Kolommen in WHERE-clausules, JOIN-condities en ORDER-BY-statements profiteren het meest van indexen. Samengestelde indexen volgen de leftmost-prefix regel.\n\nMetingen zijn belangrijker dan aannames. Elke database biedt een EXPLAIN-commando dat het geplande uitvoeringspad van een query toont. Zonder die data is optimalisatie giswerk.\n\nOver-indexering kost opslag en vertraagt schrijfacties. Combineer dit met Redis als Caching Laag voor leesintensieve workloads. Voor de complete deployment pipeline zie Docker Compose voor Lokale Ontwikkeling en Continuous Integration voor Laravel Projecten. Onderhoudbare PHPUnit Tests Schrijven helpt regressies voorkomen tijdens schema-migraties."],
            ['title' => 'Interne Verlinking als SEO Strategie', 'content' => "Een doordachte interne verlinking verbetert zowel SEO als gebruikerservaring. Link vanaf gezaghebbende paginas naar belangrijke inhoud om linkkracht effectief te verdelen. Beschrijvende ankertekst vertelt zowel gebruikers als zoekmachines waar de doelpagina over gaat.\n\nDrie tot vijf interne links per blog zijn een goede richtlijn. Te veel verwatert de linkkracht. Regelmatige audits onthullen kapotte links en verweesde inhoud. Voor content-platforms is Statamic voor Moderne Websites een solide basis, en bij API-driven sites combineer je dat met API Ontwikkeling met Laravel."],
            ['title' => 'Redis als Caching Laag', 'content' => "Redis dient als in-memory dataopslag en is uitstekend geschikt voor caches, sessies en realtime functies. Het cache-aside patroon is het meest gangbaar: lees eerst uit de cache, anders uit de database, vul daarna de cache.\n\nWrite-through houdt cache en database synchroon tijdens het schrijven. Write-behind stelt schrijfacties uit voor hogere doorvoer ten koste van complexiteit. Combineer Redis met Database Optimalisatie in de Praktijk voor leesintensieve toepassingen, en draai het lokaal via Docker Compose voor Lokale Ontwikkeling. API Ontwikkeling met Laravel profiteert direct van een cache-laag bij rate-limiting."],
            ['title' => 'API Ontwikkeling met Laravel', 'content' => "Laravel biedt uitgebreide tools voor productie-waardige REST APIs. Resource Controllers dekken CRUD af, API Resources transformeren Eloquent-modellen naar consistente JSON-antwoorden.\n\nAuthenticatie is cruciaal. Laravel Sanctum is geschikt voor lichtgewicht token-auth bij SPAs en mobiele apps. Voor volledige OAuth2 blijft Laravel Passport de referentie. Versioneer endpoints vanaf dag een. Cache antwoorden met Redis als Caching Laag, monitor de productie met Foutmonitoring met Sentry, en deploy via Continuous Integration voor Laravel Projecten. Onderhoudbare PHPUnit Tests Schrijven dekt elke endpoint af."],
            ['title' => 'Statamic voor Moderne Websites', 'content' => "Statamic is een flat-file CMS gebouwd op Laravel. Inhoud staat in YAML- en Markdown-bestanden in plaats van in een database, wat versiebeheer met Git mogelijk maakt. De Stache cached bestandsmetadata voor snelle lookups.\n\nBard biedt een blok-gebaseerde editor. Bard-inhoud wordt opgeslagen als ProseMirror JSON, wat rijke teksten met ingebedde componenten toestaat. De Fieldtype API is open — addons registreren eigen veldtypes. Voor het frontend werkt Vue Componentarchitectuur direct samen met Bard. Interne Verlinking als SEO Strategie wordt door Linkwise geautomatiseerd."],
            ['title' => 'Vue Componentarchitectuur', 'content' => "Vue kapselt template, logica en styling per component. De Composition API in Vue 3 biedt betere TypeScript-inferentie en code-organisatie dan de Options API.\n\nProps stromen naar beneden, events naar boven. Voor complexe gedeelde state tussen veel componenten is Pinia de aanbevolen store. Vermijd prop-drilling met provide/inject voor diep geneste data. In het ecosysteem combineer je Vue met Statamic voor Moderne Websites op het Control Panel, en deploy de SPA via Continuous Integration voor Laravel Projecten."],
            ['title' => 'Docker Compose voor Lokale Ontwikkeling', 'content' => "Docker Compose orkestreert meerdere containers als een samenhangende applicatie. Een typische PHP-setup omvat containers voor PHP-FPM, Nginx, MySQL of PostgreSQL en Redis. Elke service wordt gedefinieerd in docker-compose.yml.\n\nVolume mounts delen code tussen host en container, zodat bestandswijzigingen direct zichtbaar zijn. Benoemde volumes behouden databasebestanden over container-rebuilds heen. Database Optimalisatie in de Praktijk vertelt wat je in de MySQL-container moet meten. Redis als Caching Laag draait als aparte container ernaast. Continuous Integration voor Laravel Projecten gebruikt dezelfde images in CI."],
            ['title' => 'Continuous Integration voor Laravel Projecten', 'content' => "Continuous Integration draait je tests bij elke push. GitHub Actions, GitLab CI en CircleCI zijn bruikbare opties. De pipeline moet PHPUnit uitvoeren, code-stijl controleren en statische analyse zoals PHPStan draaien.\n\nContinuous Deployment automatiseert release naar staging of productie na succesvolle CI. Laravel Forge en Envoyer maken zero-downtime deployments voor PHP-applicaties mogelijk. Gebruik Docker Compose voor Lokale Ontwikkeling om de CI-omgeving te spiegelen. Onderhoudbare PHPUnit Tests Schrijven is de absolute basis. Foutmonitoring met Sentry vangt wat de pipeline niet zag."],
            ['title' => 'Foutmonitoring met Sentry', 'content' => "Foutmonitoring vangt problemen die je tests gemist hebben. Sentry aggregeert excepties met volledige stack traces, breadcrumbs en gebruikerscontext. Het gratis tier dekt kleine projecten ruim.\n\nIntegreer Sentry vroeg. De Laravel SDK heeft alleen een config-entry en een omgevingsvariabele nodig. Activeer release-tracking zodat je foutpieken kunt correleren met deployments. Combineer dit met Continuous Integration voor Laravel Projecten voor automatische release-markeringen. API Ontwikkeling met Laravel profiteert van Sentry's request-context. Onderhoudbare PHPUnit Tests Schrijven dekt de paden die Sentry vervolgens in productie monitort."],
            ['title' => 'Onderhoudbare PHPUnit Tests Schrijven', 'content' => "Een goede testsuite documenteert gedrag, voorkomt regressies en ondersteunt refactorings. Volg het Arrange-Act-Assert patroon. Elke test moet om één concrete reden falen — overladen tests verbergen wat er werkelijk kapot is.\n\nFeature tests controleren de HTTP-laag, unit tests individuele klassen geïsoleerd. Factories bouwen testdata consistent. Vermijd echte sleeps in tests — gebruik Carbons freeze en travel. API Ontwikkeling met Laravel test je met HTTP-helpers. Database Optimalisatie in de Praktijk profiteert van migration-tests. Continuous Integration voor Laravel Projecten draait dit hele suite bij elke push."],
        ];
    }
}
