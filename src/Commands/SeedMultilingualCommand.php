<?php

namespace Arturrossbach\Linkwise\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Multilingual seed for V1.x locale-scoping smoke tests.
 *
 * Creates aligned-topic translations across configured sites. For each
 * of the 10 topics the seed:
 *   1. Creates the EN entry on the default-language site as the origin
 *   2. Creates the DE entry on the de site WITH origin set to the EN entry
 *   3. Creates the NL entry on the nl site WITH origin set to the EN entry
 *
 * Result: each topic surfaces as a single "article" in the CP with three
 * proper site localizations (EN ✓ DE ✓ NL ✓ in the Sites-panel), instead of
 * three independent entries. Mirrors how real-world editorial teams ship
 * multilang content and exercises Statamic's $item->in(Site::current())
 * routing path (PR #102 audit point 3 — "Routing ungetestet").
 *
 * Sites without a matching configured language are skipped with a warning.
 * The default site (= first in sites.yaml) is used as the origin language;
 * its lang() must map to one of our pools (en / de / nl) — otherwise the
 * seed bails since there's nothing to anchor the origin chain to.
 */
class SeedMultilingualCommand extends Command
{
    protected $signature = 'linkwise:seed-multilingual
                            {count=10 : Entries per available language (each is a localization of the same topic origin)}
                            {--collection=articles : Collection handle}
                            {--locales=en,de,nl : Comma-separated ISO codes to seed (defaults to all 3 covered pools)}
                            {--independent : Create independent monolingual entries (no origin-linking). Default is to create translations linked via origin.}';

    protected $description = 'Seed multilingual articles per Statamic site for V1.x multilanguage smoke testing';

    public function handle(): int
    {
        $count = (int) $this->argument('count');
        $collectionHandle = $this->option('collection');
        $requestedLocales = array_map('trim', explode(',', (string) $this->option('locales')));
        $independent = (bool) $this->option('independent');

        $collection = Collection::findByHandle($collectionHandle);
        if (! $collection) {
            $this->error("Collection '{$collectionHandle}' does not exist. Create it first or pass --collection=<handle>.");
            return self::FAILURE;
        }

        // Map each Statamic site to its ISO language. A site is only seedable
        // if its lang() resolves to one of our pools.
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

        // Pick the origin-language site. Prefer EN (typical default), but
        // fall back to whatever locale matches the Statamic default site.
        $defaultSiteHandle = Site::default()->handle();
        $defaultLang = $this->isoFor(Site::default());

        $originLang = isset($sitesByLang['en']) ? 'en' : ($defaultLang ?: array_keys($sitesByLang)[0]);
        if (! isset($sitesByLang[$originLang])) {
            $this->error("Origin language '{$originLang}' is not among the seedable sites. Aborting.");
            return self::FAILURE;
        }

        $topics = $this->getAlignedTopics();
        $totalCreated = 0;

        for ($i = 0; $i < $count; $i++) {
            $topic = $topics[$i % count($topics)];
            $cycle = intdiv($i, count($topics));
            $suffix = $cycle === 0 ? '' : ' (Teil '.($cycle + 1).')';

            // 1. Create the origin-language entry first. The other localizations
            //    point to its ID via the `origin:` field.
            $originVariant = $topic[$originLang] ?? null;
            if ($originVariant === null) {
                $this->warn("Topic #{$i} has no {$originLang} variant — skipping.");
                continue;
            }

            $originTitle = $originVariant['title'].$suffix;
            $originSlug = \Illuminate\Support\Str::slug($originTitle).'-'.$originLang;

            $originEntry = Entry::make()
                ->collection($collectionHandle)
                ->locale($sitesByLang[$originLang])
                ->slug($originSlug)
                ->data(['title' => $originTitle, 'content' => $originVariant['content']]);
            $originEntry->save();
            $totalCreated++;
            $this->line("  [{$originLang}] {$originTitle}");

            // 2. Create each non-origin localization. Independent mode skips the
            //    origin reference so the entries surface as separate articles in
            //    the CP (the legacy pre-O1-rewrite shape, kept as fallback for
            //    testing what happens without translations).
            foreach ($sitesByLang as $iso => $siteHandle) {
                if ($iso === $originLang) continue;
                $variant = $topic[$iso] ?? null;
                if ($variant === null) {
                    $this->warn("Topic #{$i} has no {$iso} variant — skipping.");
                    continue;
                }
                $title = $variant['title'].$suffix;
                $slug = \Illuminate\Support\Str::slug($title).'-'.$iso;

                $localEntry = Entry::make()
                    ->collection($collectionHandle)
                    ->locale($siteHandle)
                    ->slug($slug)
                    ->data(['title' => $title, 'content' => $variant['content']]);

                if (! $independent) {
                    $localEntry->origin($originEntry);
                }
                $localEntry->save();
                $totalCreated++;
                $this->line("  [{$iso}".($independent ? '' : '→'.$originLang)."] {$title}");
            }
        }

        $mode = $independent ? 'independent' : 'origin-linked';
        $this->info("Created {$totalCreated} entries ({$mode}) across ".count($sitesByLang).' sites.');
        $this->info('Now run: php artisan linkwise:index (or click "Scan Content" in the CP).');

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

    /**
     * Topic-aligned pool. Each entry covers the same concept in all three
     * languages so the seed can create proper translations linked by origin.
     * 10 topics × {en, de, nl} = 30 entries at the default count.
     *
     * Bodies cross-reference at least 3 other topic titles in the SAME
     * language so the title-stem matching path has work to do within a
     * locale-bucket — without those references the within-language pool
     * produces zero suggestions even when every topic appears in every
     * language (lesson learned 2026-05-24 on the first NL smoke).
     *
     * @return list<array<string, array{title: string, content: string}>>
     */
    protected function getAlignedTopics(): array
    {
        return [
            // 0 — Database optimization
            [
                'en' => ['title' => 'Database Index Design for Read-Heavy Workloads', 'content' => "Proper indexing is the difference between a query taking milliseconds versus seconds. Index columns used in WHERE clauses, JOIN conditions, and ORDER BY statements. Composite indexes follow the leftmost prefix rule.\n\nWatch out for over-indexing. Each index adds write overhead and storage cost. Measure actual query plans rather than guessing — most database engines provide an EXPLAIN command for this purpose.\n\nCombine this with Redis Caching Patterns for Web Applications for read-heavy workloads. Local development is easiest via Docker Compose for Local PHP Development. CI/CD Pipeline Patterns for Laravel exercises migration tests, and Writing Maintainable PHPUnit Test Suites covers the data-access layer."],
                'de' => ['title' => 'Datenbankoptimierung in der Praxis', 'content' => "Die Optimierung relationaler Datenbanken beginnt bei sinnvollen Indizes. Spalten in WHERE-Klauseln, JOIN-Bedingungen und ORDER-BY-Anweisungen profitieren am stärksten von Indizes. Zusammengesetzte Indizes folgen der Leftmost-Prefix-Regel.\n\nMessungen sind wichtiger als Annahmen. Jede Datenbank bietet einen EXPLAIN-Befehl. Über-Indizierung kostet Speicher und verlangsamt Schreibzugriffe.\n\nKombiniere das mit Redis als Caching-Schicht für leseintensive Workloads. Die Pipeline läuft via Docker Compose für lokale Entwicklung und Continuous Integration für Laravel-Projekte. Wartbare PHPUnit-Tests schreiben deckt Schema-Migrationen ab."],
                'nl' => ['title' => 'Database Optimalisatie in de Praktijk', 'content' => "Het optimaliseren van relationele databases begint bij zinvolle indexen. Kolommen in WHERE-clausules, JOIN-condities en ORDER-BY-statements profiteren het meest van indexen. Samengestelde indexen volgen de leftmost-prefix regel.\n\nMetingen zijn belangrijker dan aannames. Elke database biedt een EXPLAIN-commando. Over-indexering kost opslag en vertraagt schrijfacties.\n\nCombineer dit met Redis als Caching Laag voor leesintensieve workloads. Docker Compose voor Lokale Ontwikkeling spiegelt de productieomgeving. Continuous Integration voor Laravel Projecten draait Onderhoudbare PHPUnit Tests Schrijven bij elke push."],
            ],

            // 1 — Internal linking SEO
            [
                'en' => ['title' => 'Internal Linking Strategy for Better SEO', 'content' => "A strong internal linking strategy improves both SEO and user experience. Link from high-authority pages to important content to distribute link equity effectively. Use descriptive anchor text that tells users and search engines what the linked page covers.\n\nAim for three to five internal links per blog post. Avoid over-linking which dilutes link value and confuses readers. Regularly audit your internal links to find broken targets and orphaned content.\n\nFor content-platforms Statamic CMS Architecture Overview is a solid base; pair it with API Development with Laravel when the site is headless. Database Index Design for Read-Heavy Workloads keeps the listing pages fast."],
                'de' => ['title' => 'Interne Verlinkung als SEO-Strategie', 'content' => "Eine durchdachte interne Verlinkung verbessert sowohl SEO als auch Nutzerführung. Verlinke von autoritären Seiten auf wichtige Inhalte, um Linkkraft sinnvoll zu verteilen. Beschreibender Ankertext sagt Nutzern und Suchmaschinen, worum es auf der Zielseite geht.\n\nDrei bis fünf interne Links pro Artikel sind ein guter Richtwert. Mehr verwässert die Linkkraft. Regelmäßige Audits decken kaputte Links auf.\n\nFür Content-Plattformen ist Statamic für moderne Websites eine solide Basis, kombiniert mit API-Entwicklung mit Laravel bei Headless-Sites. Datenbankoptimierung in der Praxis hält die Listingseiten schnell."],
                'nl' => ['title' => 'Interne Verlinking als SEO Strategie', 'content' => "Een doordachte interne verlinking verbetert zowel SEO als gebruikerservaring. Link vanaf gezaghebbende paginas naar belangrijke inhoud om linkkracht effectief te verdelen. Beschrijvende ankertekst vertelt zowel gebruikers als zoekmachines waar de doelpagina over gaat.\n\nDrie tot vijf interne links per blog zijn een goede richtlijn. Te veel verwatert de linkkracht. Regelmatige audits onthullen kapotte links en verweesde inhoud.\n\nVoor content-platforms is Statamic voor Moderne Websites een solide basis, gecombineerd met API Ontwikkeling met Laravel bij headless-sites. Database Optimalisatie in de Praktijk houdt de listing-paginas snel."],
            ],

            // 2 — Redis caching
            [
                'en' => ['title' => 'Redis Caching Patterns for Web Applications', 'content' => "Redis serves as an in-memory data store ideal for caching, sessions, and real-time features. Cache-aside is the most common pattern: read from cache first, fall back to the database, then populate the cache.\n\nWrite-through caching keeps cache and database in sync at write time. Write-behind defers database writes for higher throughput at the cost of complexity. Choose the pattern that matches your consistency requirements.\n\nCombine Redis with Database Index Design for Read-Heavy Workloads on read-intensive endpoints. Run it locally via Docker Compose for Local PHP Development. API Development with Laravel benefits from Redis-backed rate-limiting."],
                'de' => ['title' => 'Redis als Caching-Schicht', 'content' => "Redis dient als In-Memory-Datenspeicher und eignet sich hervorragend für Caches, Sessions und Echtzeit-Features. Das Cache-Aside-Muster ist am verbreitetsten: zuerst Cache lesen, sonst Datenbank, dann Cache füllen.\n\nWrite-Through hält Cache und Datenbank synchron beim Schreiben. Write-Behind verzögert Datenbankschreibzugriffe für höheren Durchsatz.\n\nKombiniere Redis mit Datenbankoptimierung in der Praxis bei leseintensiven Anwendungen. Lokal läuft das via Docker Compose für lokale Entwicklung. API-Entwicklung mit Laravel profitiert direkt von einer Cache-Schicht bei Rate-Limiting."],
                'nl' => ['title' => 'Redis als Caching Laag', 'content' => "Redis dient als in-memory dataopslag en is uitstekend geschikt voor caches, sessies en realtime functies. Het cache-aside patroon is het meest gangbaar: lees eerst uit de cache, anders uit de database, vul daarna de cache.\n\nWrite-through houdt cache en database synchroon tijdens het schrijven. Write-behind stelt schrijfacties uit voor hogere doorvoer.\n\nCombineer Redis met Database Optimalisatie in de Praktijk voor leesintensieve toepassingen. Lokaal draai je het via Docker Compose voor Lokale Ontwikkeling. API Ontwikkeling met Laravel profiteert direct van een cache-laag bij rate-limiting."],
            ],

            // 3 — API development with Laravel
            [
                'en' => ['title' => 'Building Reliable APIs with Laravel', 'content' => "Laravel provides comprehensive tools for building production-grade REST APIs. Resource controllers handle CRUD operations while API Resources transform Eloquent models into consistent JSON responses.\n\nAuthentication is critical for any API. Laravel Sanctum offers lightweight token-based auth ideal for SPAs. Rate limiting and proper error handling round out a production API.\n\nCache responses with Redis Caching Patterns for Web Applications. Monitor production with Sentry Error Monitoring for Production Apps. Deploy via CI/CD Pipeline Patterns for Laravel. Writing Maintainable PHPUnit Test Suites covers every endpoint."],
                'de' => ['title' => 'API-Entwicklung mit Laravel', 'content' => "Laravel bietet umfassende Werkzeuge für produktionsreife REST-APIs. Resource Controller decken CRUD ab, API Resources verwandeln Eloquent-Modelle in konsistente JSON-Antworten.\n\nAuthentifizierung ist entscheidend. Laravel Sanctum eignet sich für leichtgewichtige Token-Auth bei SPAs. Ratenbegrenzung und durchdachte Fehlerbehandlung runden eine produktive API ab.\n\nCache Antworten mit Redis als Caching-Schicht. Monitoring in Produktion via Fehlermonitoring mit Sentry. Deployment durch Continuous Integration für Laravel-Projekte. Wartbare PHPUnit-Tests schreiben deckt jeden Endpunkt ab."],
                'nl' => ['title' => 'API Ontwikkeling met Laravel', 'content' => "Laravel biedt uitgebreide tools voor productie-waardige REST APIs. Resource Controllers dekken CRUD af, API Resources transformeren Eloquent-modellen naar consistente JSON-antwoorden.\n\nAuthenticatie is cruciaal. Laravel Sanctum is geschikt voor lichtgewicht token-auth bij SPAs. Rate-limiting en doordachte foutafhandeling maken een productieve API compleet.\n\nCache antwoorden met Redis als Caching Laag. Monitor productie met Foutmonitoring met Sentry. Deploy via Continuous Integration voor Laravel Projecten. Onderhoudbare PHPUnit Tests Schrijven dekt elke endpoint af."],
            ],

            // 4 — Statamic CMS
            [
                'en' => ['title' => 'Statamic CMS Architecture Overview', 'content' => "Statamic is a flat-file CMS built on Laravel. Content lives in YAML and Markdown files rather than a database, which makes everything version-controllable. The Stache caches file metadata for fast lookups.\n\nBard provides a block-based editing experience. Bard content stores as ProseMirror JSON, enabling rich text with embedded components. The fieldtype system is extensible — addons can register custom fieldtypes.\n\nFor the frontend, Vue Component Patterns and Composition works directly with Bard rendering. Internal Linking Strategy for Better SEO is what Linkwise automates on top."],
                'de' => ['title' => 'Statamic für moderne Websites', 'content' => "Statamic ist ein Flat-File-CMS auf Basis von Laravel. Inhalte liegen in YAML- und Markdown-Dateien statt in einer Datenbank, was Versionskontrolle mit Git ermöglicht. Die Stache cached Datei-Metadaten für schnelle Lookups.\n\nBard bietet einen blockbasierten Editor. Bard-Inhalte werden als ProseMirror-JSON gespeichert, was reichhaltige Texte mit eingebetteten Komponenten erlaubt. Die Fieldtype-API ist offen.\n\nFürs Frontend arbeitet Vue-Komponentenarchitektur direkt mit Bard. Interne Verlinkung als SEO-Strategie ist genau das, was Linkwise automatisiert."],
                'nl' => ['title' => 'Statamic voor Moderne Websites', 'content' => "Statamic is een flat-file CMS gebouwd op Laravel. Inhoud staat in YAML- en Markdown-bestanden in plaats van in een database, wat versiebeheer met Git mogelijk maakt. De Stache cached bestandsmetadata voor snelle lookups.\n\nBard biedt een blok-gebaseerde editor. Bard-inhoud wordt opgeslagen als ProseMirror JSON, wat rijke teksten met ingebedde componenten toestaat. De Fieldtype API is open.\n\nVoor het frontend werkt Vue Componentarchitectuur direct samen met Bard. Interne Verlinking als SEO Strategie wordt door Linkwise geautomatiseerd."],
            ],

            // 5 — Vue components
            [
                'en' => ['title' => 'Vue Component Patterns and Composition', 'content' => "Vue's component model encapsulates template, logic, and styling. The Composition API introduced in Vue 3 provides better TypeScript inference and code organization compared to the Options API.\n\nProps flow down, events flow up. For complex state shared across many components, Pinia is the recommended store. Avoid prop-drilling by using provide/inject for tree-deep data.\n\nIn the ecosystem Vue pairs with Statamic CMS Architecture Overview on the Control Panel. Deploy the SPA via CI/CD Pipeline Patterns for Laravel. Sentry Error Monitoring for Production Apps catches client-side runtime errors."],
                'de' => ['title' => 'Vue-Komponentenarchitektur', 'content' => "Vue kapselt Template, Logik und Stil pro Komponente. Die Composition API in Vue 3 bietet bessere TypeScript-Inferenz und Code-Organisation als die Options API.\n\nProps fließen nach unten, Events nach oben. Für komplexen geteilten Zustand zwischen vielen Komponenten ist Pinia der empfohlene Store. Vermeide Prop-Drilling durch provide/inject.\n\nIm Ökosystem kombinierst du Vue mit Statamic für moderne Websites im Control Panel. Deployment der SPA via Continuous Integration für Laravel-Projekte. Fehlermonitoring mit Sentry fängt Client-Side-Errors."],
                'nl' => ['title' => 'Vue Componentarchitectuur', 'content' => "Vue kapselt template, logica en styling per component. De Composition API in Vue 3 biedt betere TypeScript-inferentie en code-organisatie dan de Options API.\n\nProps stromen naar beneden, events naar boven. Voor complexe gedeelde state tussen veel componenten is Pinia de aanbevolen store. Vermijd prop-drilling met provide/inject.\n\nIn het ecosysteem combineer je Vue met Statamic voor Moderne Websites op het Control Panel. Deploy de SPA via Continuous Integration voor Laravel Projecten. Foutmonitoring met Sentry vangt client-side runtime errors."],
            ],

            // 6 — Docker Compose
            [
                'en' => ['title' => 'Docker Compose for Local PHP Development', 'content' => "Docker Compose orchestrates multi-container applications. A typical PHP setup includes containers for PHP-FPM, Nginx, MySQL or PostgreSQL, and Redis. Each service is defined in docker-compose.yml.\n\nVolume mounts share code between host and container so file changes are reflected immediately. Use named volumes for database storage to persist data across container rebuilds.\n\nDatabase Index Design for Read-Heavy Workloads tells you what to measure in the MySQL container. Redis Caching Patterns for Web Applications runs as a sibling container. CI/CD Pipeline Patterns for Laravel uses the same images in CI."],
                'de' => ['title' => 'Docker Compose für lokale Entwicklung', 'content' => "Docker Compose orchestriert mehrere Container als zusammenhängende Anwendung. Ein typisches PHP-Setup umfasst Container für PHP-FPM, Nginx, MySQL oder PostgreSQL und Redis. Jeder Service wird in docker-compose.yml definiert.\n\nVolume Mounts teilen Code zwischen Host und Container, sodass Dateiänderungen sofort sichtbar sind. Benannte Volumes persistieren Datenbankdateien über Container-Rebuilds hinweg.\n\nDatenbankoptimierung in der Praxis sagt dir, was du im MySQL-Container messen sollst. Redis als Caching-Schicht läuft als Schwester-Container. Continuous Integration für Laravel-Projekte nutzt die gleichen Images in CI."],
                'nl' => ['title' => 'Docker Compose voor Lokale Ontwikkeling', 'content' => "Docker Compose orkestreert meerdere containers als een samenhangende applicatie. Een typische PHP-setup omvat containers voor PHP-FPM, Nginx, MySQL of PostgreSQL en Redis. Elke service wordt gedefinieerd in docker-compose.yml.\n\nVolume mounts delen code tussen host en container, zodat bestandswijzigingen direct zichtbaar zijn. Benoemde volumes behouden databasebestanden over container-rebuilds heen.\n\nDatabase Optimalisatie in de Praktijk vertelt wat je in de MySQL-container moet meten. Redis als Caching Laag draait als aparte container ernaast. Continuous Integration voor Laravel Projecten gebruikt dezelfde images in CI."],
            ],

            // 7 — CI/CD
            [
                'en' => ['title' => 'CI/CD Pipeline Patterns for Laravel', 'content' => "Continuous integration runs your test suite on every push. GitHub Actions, GitLab CI, and CircleCI are all viable choices. The pipeline should run PHPUnit, code-style checks, and static analysis like PHPStan.\n\nContinuous deployment automates release to staging or production after CI passes. Laravel Forge and Envoyer streamline zero-downtime deployments for PHP applications.\n\nUse Docker Compose for Local PHP Development to mirror the CI environment. Writing Maintainable PHPUnit Test Suites is the absolute baseline. Sentry Error Monitoring for Production Apps catches what the pipeline didn't."],
                'de' => ['title' => 'Continuous Integration für Laravel-Projekte', 'content' => "Continuous Integration führt deine Tests bei jedem Push aus. GitHub Actions, GitLab CI und CircleCI sind brauchbare Optionen. Die Pipeline sollte PHPUnit ausführen, Code-Style prüfen und statische Analyse wie PHPStan durchlaufen.\n\nContinuous Deployment automatisiert das Release in Staging oder Produktion nach erfolgreicher CI. Laravel Forge und Envoyer ermöglichen Zero-Downtime-Deployments.\n\nNutze Docker Compose für lokale Entwicklung, um die CI-Umgebung zu spiegeln. Wartbare PHPUnit-Tests schreiben ist die absolute Basis. Fehlermonitoring mit Sentry fängt was die Pipeline übersehen hat."],
                'nl' => ['title' => 'Continuous Integration voor Laravel Projecten', 'content' => "Continuous Integration draait je tests bij elke push. GitHub Actions, GitLab CI en CircleCI zijn bruikbare opties. De pipeline moet PHPUnit uitvoeren, code-stijl controleren en statische analyse zoals PHPStan draaien.\n\nContinuous Deployment automatiseert release naar staging of productie na succesvolle CI. Laravel Forge en Envoyer maken zero-downtime deployments voor PHP-applicaties mogelijk.\n\nGebruik Docker Compose voor Lokale Ontwikkeling om de CI-omgeving te spiegelen. Onderhoudbare PHPUnit Tests Schrijven is de absolute basis. Foutmonitoring met Sentry vangt wat de pipeline niet zag."],
            ],

            // 8 — Sentry
            [
                'en' => ['title' => 'Sentry Error Monitoring for Production Apps', 'content' => "Error monitoring catches issues your tests missed. Sentry aggregates exceptions across all your services with full stack traces, breadcrumbs, and user context. The free tier covers small projects comfortably.\n\nIntegrate Sentry early in development. The Laravel SDK requires only a config entry and an environment variable. Configure release tracking so you can correlate spikes with deployments.\n\nPair this with CI/CD Pipeline Patterns for Laravel for automatic release tagging. Building Reliable APIs with Laravel benefits from Sentry's request context. Writing Maintainable PHPUnit Test Suites covers paths Sentry then monitors in production."],
                'de' => ['title' => 'Fehlermonitoring mit Sentry', 'content' => "Fehlermonitoring fängt Probleme, die deine Tests übersehen haben. Sentry aggregiert Exceptions mit vollständigen Stack-Traces, Breadcrumbs und Nutzerkontext. Der kostenlose Tarif reicht für kleine Projekte aus.\n\nIntegriere Sentry früh. Das Laravel-SDK braucht nur einen Config-Eintrag und eine Umgebungsvariable. Aktiviere Release-Tracking, damit du Fehlerspitzen mit Deployments korrelieren kannst.\n\nKombiniere das mit Continuous Integration für Laravel-Projekte für automatische Release-Tags. API-Entwicklung mit Laravel profitiert von Sentry's Request-Context. Wartbare PHPUnit-Tests schreiben deckt die Pfade ab, die Sentry dann in Produktion monitort."],
                'nl' => ['title' => 'Foutmonitoring met Sentry', 'content' => "Foutmonitoring vangt problemen die je tests gemist hebben. Sentry aggregeert excepties met volledige stack traces, breadcrumbs en gebruikerscontext. Het gratis tier dekt kleine projecten ruim.\n\nIntegreer Sentry vroeg. De Laravel SDK heeft alleen een config-entry en een omgevingsvariabele nodig. Activeer release-tracking zodat je foutpieken kunt correleren met deployments.\n\nCombineer dit met Continuous Integration voor Laravel Projecten voor automatische release-markeringen. API Ontwikkeling met Laravel profiteert van Sentry's request-context. Onderhoudbare PHPUnit Tests Schrijven dekt de paden die Sentry vervolgens in productie monitort."],
            ],

            // 9 — PHPUnit
            [
                'en' => ['title' => 'Writing Maintainable PHPUnit Test Suites', 'content' => "A good test suite documents behavior, prevents regressions, and supports refactoring. Follow Arrange-Act-Assert. Each test should fail for one specific reason — fat tests with many assertions hide which behavior actually broke.\n\nFeature tests cover the HTTP layer; Unit tests cover individual classes in isolation. Use factories to build test data. Avoid sleeping in tests — use Carbon's freeze and travel instead.\n\nBuilding Reliable APIs with Laravel is tested with HTTP helpers. Database Index Design for Read-Heavy Workloads benefits from migration tests. CI/CD Pipeline Patterns for Laravel runs this whole suite on every push."],
                'de' => ['title' => 'Wartbare PHPUnit-Tests schreiben', 'content' => "Eine gute Testsuite dokumentiert Verhalten, verhindert Regressionen und unterstützt Refactorings. Folge dem Arrange-Act-Assert-Muster. Jeder Test sollte aus einem konkreten Grund fehlschlagen — überfrachtete Tests verstecken, was tatsächlich kaputt ist.\n\nFeature Tests prüfen die HTTP-Schicht, Unit Tests einzelne Klassen isoliert. Factories bauen Testdaten konsistent. Vermeide echte Sleeps in Tests — nutze Carbons freeze und travel.\n\nAPI-Entwicklung mit Laravel testet du mit HTTP-Helpern. Datenbankoptimierung in der Praxis profitiert von Migration-Tests. Continuous Integration für Laravel-Projekte fährt die gesamte Suite bei jedem Push."],
                'nl' => ['title' => 'Onderhoudbare PHPUnit Tests Schrijven', 'content' => "Een goede testsuite documenteert gedrag, voorkomt regressies en ondersteunt refactorings. Volg het Arrange-Act-Assert patroon. Elke test moet om één concrete reden falen — overladen tests verbergen wat er werkelijk kapot is.\n\nFeature tests controleren de HTTP-laag, unit tests individuele klassen geïsoleerd. Factories bouwen testdata consistent. Vermijd echte sleeps in tests — gebruik Carbons freeze en travel.\n\nAPI Ontwikkeling met Laravel test je met HTTP-helpers. Database Optimalisatie in de Praktijk profiteert van migration-tests. Continuous Integration voor Laravel Projecten draait deze hele suite bij elke push."],
            ],
        ];
    }
}
