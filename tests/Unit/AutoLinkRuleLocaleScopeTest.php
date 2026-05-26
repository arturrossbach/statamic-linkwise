<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\AutoLink\AutoLinkRule;
use Arturrossbach\Linkwise\Subscribers\AutoLinkOnEntrySaveSubscriber;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * V1.2 Cross-Tab-B pins. AutoLinkRule's per-locale scope MUST:
 *
 * 1. Default to "all sites" when the `locales` field is missing or empty.
 *    Pre-V1.2 rules in the JSON store have no `locales` key at all; they
 *    must continue to match-all without migration.
 * 2. Restrict matching when `locales` is a non-empty list.
 * 3. Be null-safe for legacy/single-site entries (entry.locale === null
 *    passes regardless of rule scope — same convention as the
 *    SuggestionEngine same-locale filter).
 * 4. Strip junk + dedup at parse time (`['DE', 'de', '']` → `['de']`).
 * 5. Be honored by BOTH AutoLinkApplier::applyRule path AND
 *    AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave (the on-save
 *    code path is the silent leak — without #5 a DE-only rule would
 *    fire on EN entries every time the editor saves).
 */
class AutoLinkRuleLocaleScopeTest extends TestCase
{
    public function test_empty_locales_means_match_all(): void
    {
        $rule = AutoLinkRule::create(['keyword' => 'x', 'url' => '/']);
        $this->assertSame([], $rule->locales);
        $this->assertTrue($rule->matchesLocale('en'));
        $this->assertTrue($rule->matchesLocale('de'));
        $this->assertTrue($rule->matchesLocale(null));
    }

    public function test_legacy_rule_without_locales_field_loads_as_match_all(): void
    {
        // Pre-V1.2 JSON store entries had no `locales` key. fromArray must
        // default + match-all without migration.
        $rule = AutoLinkRule::fromArray([
            'id' => 'r1',
            'keyword' => 'Database',
            'url' => 'statamic://entry::abc',
        ]);
        $this->assertSame([], $rule->locales);
        $this->assertTrue($rule->matchesLocale('en'));
    }

    public function test_non_empty_locales_restricts_to_listed_codes(): void
    {
        $rule = AutoLinkRule::create([
            'keyword' => 'Datenbank',
            'url' => '/',
            'locales' => ['de'],
        ]);
        $this->assertSame(['de'], $rule->locales);
        $this->assertTrue($rule->matchesLocale('de'));
        $this->assertFalse($rule->matchesLocale('en'));
        $this->assertFalse($rule->matchesLocale('nl'));
    }

    public function test_null_entry_locale_passes_even_with_restricted_rule(): void
    {
        // Legacy entries (pre-PR-#101) have locale=null. Same null-safety
        // convention as SuggestionEngine — filter only fires when BOTH
        // sides have a locale. Otherwise we'd silently disappear entries
        // for users who haven't reindexed yet.
        $rule = AutoLinkRule::create([
            'keyword' => 'x',
            'url' => '/',
            'locales' => ['de'],
        ]);
        $this->assertTrue($rule->matchesLocale(null));
    }

    public function test_normalize_strips_dedupes_and_lowercases(): void
    {
        $rule = AutoLinkRule::create([
            'keyword' => 'x',
            'url' => '/',
            'locales' => ['DE', 'de', '', 'EN', null, 'NL', '  fr  '],
        ]);
        // Lowercased, trimmed, deduped, empty + non-string filtered out.
        $sorted = $rule->locales;
        sort($sorted);
        $this->assertSame(['de', 'en', 'fr', 'nl'], $sorted);
    }

    public function test_subscriber_eligible_rules_filters_by_entry_locale(): void
    {
        // The on-save code path is the silent-leak guard. Without locale-
        // filtering here, a DE-only rule would fire when an EN editor
        // saves an entry whose body happens to contain the DE keyword.
        $deOnly = AutoLinkRule::create(['keyword' => 'a', 'url' => '/a', 'locales' => ['de']]);
        $enOnly = AutoLinkRule::create(['keyword' => 'b', 'url' => '/b', 'locales' => ['en']]);
        $any = AutoLinkRule::create(['keyword' => 'c', 'url' => '/c']);

        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave(
            [$deOnly, $enOnly, $any],
            entryId: 'some-entry',
            entryCollection: 'articles',
            globalEnabled: true,
            entryLocale: 'en',
        );
        $keywords = array_map(fn ($r) => $r->keyword, $eligible);
        sort($keywords);
        $this->assertSame(['b', 'c'], $keywords, 'EN-locale entry must skip DE-only rule.');
    }

    public function test_subscriber_eligible_rules_null_locale_passes_all(): void
    {
        // Single-site / legacy entry path.
        $deOnly = AutoLinkRule::create(['keyword' => 'a', 'url' => '/a', 'locales' => ['de']]);
        $any = AutoLinkRule::create(['keyword' => 'c', 'url' => '/c']);

        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave(
            [$deOnly, $any],
            entryId: 'x',
            entryCollection: 'articles',
            globalEnabled: true,
            entryLocale: null,
        );
        $this->assertCount(2, $eligible);
    }
}
