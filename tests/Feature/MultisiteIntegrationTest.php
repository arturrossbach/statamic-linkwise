<?php

namespace Arturrossbach\Linkwise\Tests\Feature;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use Arturrossbach\Linkwise\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Integration test for the multisite/multilanguage path that unit tests
 * can't exercise. PR #101 + PR #102 audit O1.
 *
 * Hand-constructed `EntryRecord` fixtures in unit tests verify the
 * SuggestionEngine logic but bypass the real upstream:
 *   `$entry->site()->lang() → LanguageRegistry::resolveFor() → EntryRecord::$locale`.
 *
 * This test boots Statamic with two configured sites, creates Entry objects
 * bound to each site via `Entry::make()->locale($handle)`, runs the actual
 * `EntryIndexer::indexEntry()` per entry, and asserts the per-site locale
 * stamp flows end-to-end through the real Statamic API. If the upstream
 * signature changes (Statamic 7+ may move `lang()` semantics) this test
 * fails loudly where unit tests would still pass.
 *
 * Deliberately bypasses `Entry::save()` and the full Stache disk dance —
 * those introduce a per-test filesystem hot path and brittle cache
 * invalidation that would dwarf the actual integration boundary we want to
 * pin. The integration here is: `$entry->site()->lang()` returns the right
 * value AND the Indexer stamps it. That's a direct method-graph call
 * on in-memory Entry instances, not a save/load round trip.
 *
 * The `localizable` flag paths (A1/A2) are covered by hand-constructed
 * unit fixtures in {@see \Arturrossbach\Linkwise\Tests\Unit\SuggestionEngineLocaleScopingTest};
 * extracting them here would require a real on-disk blueprint and 3x setup.
 */
class MultisiteIntegrationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.system.multisite', true);
        $app['config']->set('statamic.sites.sites', [
            'default' => [
                'name' => 'EN',
                'url' => 'http://localhost/',
                'locale' => 'en_US',
                'lang' => 'en',
            ],
            'de' => [
                'name' => 'DE',
                'url' => 'http://localhost/de/',
                'locale' => 'de_DE',
                'lang' => 'de',
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Statamic reads sites from `resources/sites.yaml`, NOT from the
        // `statamic.sites.sites` config key — see `Sites::getSavedSites()`.
        // Push the in-memory hash directly into the facade instead of trying
        // to round-trip through YAML.
        Site::setSites([
            'default' => ['name' => 'EN', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'de' => ['name' => 'DE', 'url' => 'http://localhost/de/', 'locale' => 'de_DE', 'lang' => 'de'],
        ]);

        // A multisite collection with both sites declared — needed so
        // Entry->site() resolves correctly. `save()` here writes a tiny
        // collection.yaml stub which the rest of the test ignores.
        Collection::make('articles')->sites(['default', 'de'])->save();
    }

    public function test_site_lang_returns_expected_iso(): void
    {
        // Sanity gate before the indexer-level tests: confirm Statamic's
        // own API gives us back what we expect. If THIS fails, the failure
        // is in fixture setup, not in Linkwise.
        $this->assertSame('en', Site::get('default')->lang());
        $this->assertSame('de', Site::get('de')->lang());
    }

    public function test_indexer_stamps_locale_from_entry_site(): void
    {
        $en = Entry::make()
            ->id('en-test-id')
            ->collection('articles')
            ->locale('default')
            ->slug('en-article')
            ->data(['title' => 'English Article About Database']);

        $de = Entry::make()
            ->id('de-test-id')
            ->collection('articles')
            ->locale('de')
            ->slug('de-artikel')
            ->data(['title' => 'Deutscher Artikel über Datenbank']);

        $indexer = new EntryIndexer;
        $enRecord = $indexer->indexEntry($en);
        $deRecord = $indexer->indexEntry($de);

        $this->assertNotNull($enRecord);
        $this->assertNotNull($deRecord);
        $this->assertSame('en', $enRecord->locale, '$entry->site()->lang() must produce en for the default site');
        $this->assertSame('de', $deRecord->locale, '$entry->site()->lang() must produce de for the de site');
    }

    public function test_suggest_filters_cross_locale_via_indexed_records(): void
    {
        // Source is an EN entry; target is a DE entry with the same root
        // keyword. Without the locale filter, the title-stem path would
        // fire EN→DE. With it, suggestions must be empty.
        $en = Entry::make()
            ->id('source-en-id')
            ->collection('articles')
            ->locale('default')
            ->slug('source-en')
            ->data(['title' => 'Source About Datenbank']);

        $de = Entry::make()
            ->id('ziel-de-id')
            ->collection('articles')
            ->locale('de')
            ->slug('ziel-de')
            ->data(['title' => 'Datenbank Optimierung']);

        $indexer = new EntryIndexer;
        $enRecord = $indexer->indexEntry($en);
        $deRecord = $indexer->indexEntry($de);

        $index = [
            $enRecord->id => $enRecord,
            $deRecord->id => $deRecord,
        ];

        $suggestions = (new SuggestionEngine)->suggest(
            'We use Datenbank extensively in our pipeline.',
            $index,
            $enRecord->id,
        );

        $crossLocale = array_filter(
            $suggestions,
            fn ($s) => ($index[$s->targetEntryId]->locale ?? null) !== 'en'
        );

        $this->assertEmpty(
            $crossLocale,
            'EN source must not suggest DE targets via the real Indexer-stamped locale path.'
        );
    }
}
