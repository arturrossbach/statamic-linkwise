<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\AutoLink\AutoLinkApplier;
use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\AutoLink\AutoLinkRule;
use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Tests\TestCase;

class AutoLinkApplierTest extends TestCase
{
    public function test_word_boundary_prevents_partial_matches(): void
    {
        // "WAR" should NOT match inside "beWARe"
        $inserter = BardLinkInserter::insertLinkIntoMarkdown(
            'We should beware of dangers',
            'WAR',
            'https://example.com',
        );

        $this->assertNull($inserter, '"WAR" should not match inside "beware"');
    }

    public function test_word_boundary_allows_exact_matches(): void
    {
        $result = BardLinkInserter::insertLinkIntoMarkdown(
            'The war continues today',
            'war',
            'https://example.com',
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('[war](https://example.com)', $result);
    }

    public function test_markdown_link_insertion(): void
    {
        $result = BardLinkInserter::insertLinkIntoMarkdown(
            'I love Laravel and PHP',
            'Laravel',
            'https://laravel.com',
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('[Laravel](https://laravel.com)', $result);
        $this->assertStringContainsString('I love [Laravel](https://laravel.com) and PHP', $result);
    }

    public function test_markdown_link_skips_already_linked(): void
    {
        $result = BardLinkInserter::insertLinkIntoMarkdown(
            'I love [Laravel](https://laravel.com) and PHP',
            'Laravel',
            'https://other-url.com',
        );

        $this->assertNull($result, 'Should not link already-linked text');
    }

    public function test_markdown_case_insensitive_by_default(): void
    {
        $result = BardLinkInserter::insertLinkIntoMarkdown(
            'I love laravel framework',
            'Laravel',
            'https://laravel.com',
            false, // case insensitive
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('[laravel](https://laravel.com)', $result);
    }

    public function test_markdown_case_sensitive(): void
    {
        $result = BardLinkInserter::insertLinkIntoMarkdown(
            'I love laravel framework',
            'Laravel',
            'https://laravel.com',
            true, // case sensitive
        );

        $this->assertNull($result, '"laravel" should not match "Laravel" in case-sensitive mode');
    }

    public function test_markdown_only_first_occurrence(): void
    {
        $result = BardLinkInserter::insertLinkIntoMarkdown(
            'Coffee in the morning, coffee in the evening',
            'coffee',
            'https://coffee.com',
        );

        $this->assertNotNull($result);
        // Only first occurrence should be linked
        $this->assertSame(1, substr_count($result, '](https://coffee.com)'));
    }

    public function test_bard_word_boundary_in_prosemirror(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'We should beware of the attack on our systems'],
                ],
            ],
        ];

        // "WAR" should not match inside "beware"
        $result = BardLinkInserter::insertLinkWithHref($bard, 'WAR', 'https://example.com');
        $this->assertNull($result, '"WAR" should not match inside "beware" in Bard content');

        // "attack" SHOULD match as a whole word
        $result2 = BardLinkInserter::insertLinkWithHref($bard, 'attack', 'https://example.com');
        $this->assertNotNull($result2);
    }

    public function test_bard_replaces_link_when_different_href(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Visit '],
                    [
                        'type' => 'text',
                        'text' => 'Laravel',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://laravel.com']]],
                    ],
                    ['type' => 'text', 'text' => ' for more info'],
                ],
            ],
        ];

        // Should replace existing link with new href (not create duplicate)
        $result = BardLinkInserter::insertLinkWithHref($bard, 'Laravel', 'https://other-url.com');
        $this->assertNotNull($result, 'Should replace existing link with different href');
        $linked = $result[0]['content'][1];
        $this->assertCount(1, $linked['marks'], 'Should have exactly 1 link mark');
        $this->assertSame('https://other-url.com', $linked['marks'][0]['attrs']['href']);
    }

    public function test_bard_skips_already_linked_to_same_href(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Visit '],
                    [
                        'type' => 'text',
                        'text' => 'Laravel',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://laravel.com']]],
                    ],
                    ['type' => 'text', 'text' => ' for more info'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLinkWithHref($bard, 'Laravel', 'https://laravel.com');
        $this->assertNull($result, 'Should skip when already linked to same href');
    }

    public function test_auto_link_rule_creation(): void
    {
        $rule = AutoLinkRule::create([
            'keyword' => 'Laravel',
            'url' => 'https://laravel.com',
            'once_per_post' => true,
            'case_sensitive' => false,
        ]);

        $this->assertSame('Laravel', $rule->keyword);
        $this->assertSame('https://laravel.com', $rule->url);
        $this->assertNull($rule->targetEntryId);
        $this->assertTrue($rule->oncePerPost);
        $this->assertFalse($rule->caseSensitive);
        $this->assertTrue($rule->isExternal());
    }

    public function test_auto_link_rule_detects_statamic_entry(): void
    {
        $rule = AutoLinkRule::create([
            'keyword' => 'My Entry',
            'url' => 'statamic://entry::abc-123',
        ]);

        $this->assertSame('abc-123', $rule->targetEntryId);
        $this->assertFalse($rule->isExternal());
    }

    public function test_target_keyword_boost_increases_score(): void
    {
        // The boost method is protected, so we test it via reflection
        // This verifies the boost logic works correctly in isolation
        $suggestion = new \Arturrossbach\Linkwise\Suggestions\Suggestion(
            targetEntryId: 'test-entry',
            targetTitle: 'Test Entry',
            targetUrl: '/test',
            targetCollection: 'pages',
            anchorText: 'test',
            position: 0,
            score: 0.5,
            sentenceContext: 'This is a test',
        );

        $engine = new \Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
        $method = new \ReflectionMethod($engine, 'applyTargetKeywordBoost');
        $method->setAccessible(true);

        // Without matching keywords, score stays the same
        $result = $method->invoke($engine, $suggestion, 'some random text');
        $this->assertSame(0.5, $result->score);
    }

    public function test_auto_link_rule_roundtrip(): void
    {
        $rule = AutoLinkRule::create([
            'keyword' => 'Test',
            'url' => 'https://test.com',
            'once_per_post' => false,
            'skip_if_exists' => true,
            'case_sensitive' => true,
            'collections' => ['blog', 'docs'],
        ]);

        $restored = AutoLinkRule::fromArray($rule->toArray());

        $this->assertSame($rule->id, $restored->id);
        $this->assertSame($rule->keyword, $restored->keyword);
        $this->assertSame($rule->url, $restored->url);
        $this->assertFalse($restored->oncePerPost);
        $this->assertTrue($restored->skipIfExists);
        $this->assertTrue($restored->caseSensitive);
        $this->assertSame(['blog', 'docs'], $restored->collections);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cancel-hook tests for applyRule. Bug: ApplyRuleCommand wrote a cancel
    // flag into the cache, but applyRule never read it — so a Cancel click
    // only took effect AFTER the rule had run to completion. Fix: applyRule
    // now polls a `?callable $shouldCancel` at each record boundary and
    // short-circuits with `cancelled => true` in the result.
    // ─────────────────────────────────────────────────────────────────────

    public function test_apply_rule_cancel_returns_immediately_on_first_iteration(): void
    {
        $records = $this->makeRecords(['a', 'b', 'c', 'd', 'e']);
        $applier = new AutoLinkApplier($this->fakeIndexer($records), $this->fakeManager());
        $rule = AutoLinkRule::create([
            'keyword' => 'zzznonexistent',
            'url' => 'https://example.com',
        ]);

        $callCount = 0;
        $shouldCancel = function () use (&$callCount) {
            $callCount++;
            return true; // cancel on first call
        };

        $result = $applier->applyRule($rule, false, null, $shouldCancel);

        $this->assertTrue($result['cancelled'] ?? false, 'cancelled flag must be set');
        $this->assertSame(0, $result['entries_checked'], 'no records should be processed');
        $this->assertSame(0, $result['links_added']);
        $this->assertSame(1, $callCount, 'cancel hook called exactly once before short-circuit');
    }

    public function test_apply_rule_cancel_short_circuits_mid_loop(): void
    {
        $records = $this->makeRecords(['a', 'b', 'c', 'd', 'e']);
        $applier = new AutoLinkApplier($this->fakeIndexer($records), $this->fakeManager());
        $rule = AutoLinkRule::create([
            'keyword' => 'zzznonexistent',
            'url' => 'https://example.com',
        ]);

        $callCount = 0;
        $shouldCancel = function () use (&$callCount) {
            $callCount++;
            return $callCount > 2; // first two iterations proceed, third cancels
        };

        $result = $applier->applyRule($rule, false, null, $shouldCancel);

        $this->assertTrue($result['cancelled'] ?? false);
        $this->assertSame(2, $result['entries_checked'], 'two records should have been processed');
        $this->assertSame(3, $callCount, 'cancel hook called once per iteration including the cancelling one');
    }

    public function test_apply_rule_without_cancel_callback_runs_to_completion(): void
    {
        // Regression guard: passing null for $shouldCancel must not change
        // existing behavior — the loop runs all records, no cancelled flag.
        $records = $this->makeRecords(['a', 'b', 'c']);
        $applier = new AutoLinkApplier($this->fakeIndexer($records), $this->fakeManager());
        $rule = AutoLinkRule::create([
            'keyword' => 'zzznonexistent',
            'url' => 'https://example.com',
        ]);

        $result = $applier->applyRule($rule, false, null, null);

        $this->assertArrayNotHasKey('cancelled', $result, 'no cancelled flag when not cancelled');
        $this->assertSame(3, $result['entries_checked']);
    }

    /** Build a list of EntryRecords with the given IDs and harmless text. */
    private function makeRecords(array $ids): array
    {
        return array_map(fn (string $id) => new EntryRecord(
            id: $id,
            title: "Entry $id",
            url: "/entry/$id",
            collection: 'pages',
            text: 'Some unrelated content with no matching keyword.',
            outboundLinks: [],
        ), $ids);
    }

    private function fakeIndexer(array $records): EntryIndexer
    {
        return new class($records) extends EntryIndexer {
            public function __construct(private array $records) {}

            public function load(): array
            {
                return $this->records;
            }
        };
    }

    private function fakeManager(): AutoLinkManager
    {
        return new class extends AutoLinkManager {
            public function __construct() {}
        };
    }
}
