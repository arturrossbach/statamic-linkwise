<?php

namespace Inkline\Linkwise\Tests\Unit;

use Inkline\Linkwise\Indexer\EntryRecord;
use Inkline\Linkwise\Reports\LinkReport;
use Inkline\Linkwise\Suggestions\InboundSuggestion;
use Inkline\Linkwise\Suggestions\Suggestion;
use Inkline\Linkwise\Suggestions\SuggestionEngine;
use Inkline\Linkwise\Support\BardLinkInserter;
use Inkline\Linkwise\Support\TextExtractor;
use PHPUnit\Framework\TestCase;

class ConsistencyTest extends TestCase
{
    private function makeRecord(string $id, string $title, string $text = '', array $outbound = [], array $keywords = []): EntryRecord
    {
        return new EntryRecord(
            id: $id,
            title: $title,
            url: '/'.$id,
            collection: 'pages',
            text: $text,
            outboundLinks: $outbound,
            keywords: $keywords,
        );
    }

    // --- Suggestion Engine Consistency ---

    public function test_never_suggests_self(): void
    {
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.4);
        $index = [
            'a' => $this->makeRecord('a', 'Laravel Setup Guide', 'This is about the Laravel Setup Guide for developers'),
        ];

        $suggestions = $engine->suggest('Read the Laravel Setup Guide', $index, 'a');
        $this->assertEmpty($suggestions, 'Should not suggest linking to self');
    }

    public function test_scores_are_within_valid_range(): void
    {
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.1);
        $index = [
            'a' => $this->makeRecord('a', 'Redis Setup', 'Redis configuration guide'),
            'b' => $this->makeRecord('b', 'Laravel Caching', 'How to cache with Laravel', [], ['laravel' => 0.5, 'cach' => 0.4]),
        ];

        $suggestions = $engine->suggest('Follow the Redis Setup guide and use Laravel caching', $index);

        foreach ($suggestions as $s) {
            $this->assertGreaterThanOrEqual(0, $s->score);
            $this->assertLessThanOrEqual(1.0, $s->score);
        }
    }

    public function test_title_match_scores_higher_than_keyword_match(): void
    {
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.1, minKeywordScore: 0.1);
        $index = [
            'a' => $this->makeRecord('a', 'Redis Setup', ''),
            'b' => $this->makeRecord('b', 'Performance Tips', '', [], ['redi' => 0.5, 'perform' => 0.3]),
        ];

        $text = 'Follow the Redis Setup guide for better performance with Redis caching';
        $suggestions = $engine->suggest($text, $index);

        $titleMatch = collect($suggestions)->firstWhere('targetEntryId', 'a');
        $keywordMatch = collect($suggestions)->firstWhere('targetEntryId', 'b');

        if ($titleMatch && $keywordMatch) {
            $this->assertGreaterThan($keywordMatch->score, $titleMatch->score, 'Title match should score higher than keyword match');
        }

        $this->assertNotEmpty($suggestions);
    }

    public function test_excludes_already_linked_entries(): void
    {
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.4);
        $index = [
            'a' => $this->makeRecord('a', 'Redis Setup', ''),
            'current' => $this->makeRecord('current', 'My Article', '', ['a']),
        ];

        $suggestions = $engine->suggest('Read the Redis Setup guide', $index, 'current');
        $this->assertEmpty($suggestions, 'Should not suggest already-linked entry');
    }

    public function test_anchor_text_is_never_empty(): void
    {
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.1, minKeywordScore: 0.1);
        $index = [
            'a' => $this->makeRecord('a', 'Redis Caching Guide', '', [], ['redi' => 0.5, 'cach' => 0.4, 'guid' => 0.3]),
        ];

        $suggestions = $engine->suggest('We use Redis caching for all our production servers', $index);

        foreach ($suggestions as $s) {
            $this->assertNotEmpty($s->anchorText, 'Anchor text should never be empty');
        }
    }

    public function test_anchor_respects_word_boundaries(): void
    {
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.4);
        $index = [
            'a' => $this->makeRecord('a', 'Redis Setup', ''),
        ];

        // "Redis Setupper" should NOT match
        $suggestions = $engine->suggest('The Redis Setupper tool is great', $index);
        $this->assertEmpty($suggestions);

        // "Redis Setup" SHOULD match
        $suggestions2 = $engine->suggest('Follow the Redis Setup guide', $index);
        $this->assertNotEmpty($suggestions2);
    }

    // --- Link Report Consistency ---

    public function test_orphaned_count_matches_orphaned_entries(): void
    {
        $records = [
            'a' => $this->makeRecord('a', 'Entry A', '', ['b']),
            'b' => $this->makeRecord('b', 'Entry B', ''),
            'c' => $this->makeRecord('c', 'Entry C', ''),
        ];

        $report = new LinkReport($records);
        $this->assertSame(count($report->orphanedEntries()), $report->orphanedCount());
    }

    public function test_inbound_plus_orphaned_equals_total(): void
    {
        $records = [
            'a' => $this->makeRecord('a', 'Entry A', '', ['b']),
            'b' => $this->makeRecord('b', 'Entry B', '', ['c']),
            'c' => $this->makeRecord('c', 'Entry C', ''),
        ];

        $report = new LinkReport($records);
        $linked = count(array_filter($records, fn ($r) => $report->inboundCount($r->id) > 0));
        $orphaned = $report->orphanedCount();

        $this->assertSame($report->totalEntries(), $linked + $orphaned);
    }

    public function test_health_coverage_is_percentage(): void
    {
        $records = [
            'a' => $this->makeRecord('a', 'Entry A', '', ['b']),
            'b' => $this->makeRecord('b', 'Entry B', ''),
        ];

        $report = new LinkReport($records);
        $health = $report->health();

        $this->assertGreaterThanOrEqual(0, $health['coverage']);
        $this->assertLessThanOrEqual(100, $health['coverage']);
    }

    // --- Text Extractor Consistency ---

    public function test_internal_links_with_anchor_returns_anchor_text(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Click '],
                    [
                        'type' => 'text',
                        'text' => 'here',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123']]],
                    ],
                    ['type' => 'text', 'text' => ' for more.'],
                ],
            ],
        ];

        $links = TextExtractor::internalLinksWithAnchorFromBard($bard);
        $this->assertCount(1, $links);
        $this->assertSame('abc-123', $links[0]['entry_id']);
        $this->assertSame('here', $links[0]['anchor_text']);
    }

    public function test_external_links_extraction_consistent(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Visit Google',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://google.com']]],
                    ],
                ],
            ],
        ];

        $external = TextExtractor::externalLinksFromBard($bard);
        $internal = TextExtractor::linksFromBard($bard);

        $this->assertCount(1, $external);
        $this->assertEmpty($internal, 'External links should not appear in internal links');
    }

    public function test_markdown_links_extraction(): void
    {
        $md = 'Check [Laravel](https://laravel.com) and [Redis](statamic://entry::abc-123)';

        $external = TextExtractor::externalLinksFromMarkdown($md);
        $internal = TextExtractor::linksFromMarkdown($md);

        $this->assertCount(1, $external);
        $this->assertSame('https://laravel.com', $external[0]['url']);
        $this->assertCount(1, $internal);
        $this->assertSame('abc-123', $internal[0]);
    }

    // --- BardLinkInserter Consistency ---

    public function test_insert_into_markdown_produces_valid_link(): void
    {
        $result = BardLinkInserter::insertLinkIntoMarkdown(
            'I love Laravel framework',
            'Laravel',
            'https://laravel.com',
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('[Laravel](https://laravel.com)', $result);

        // Re-extraction should find the link
        $links = TextExtractor::externalLinksFromMarkdown($result);
        $this->assertCount(1, $links);
        $this->assertSame('https://laravel.com', $links[0]['url']);
        $this->assertSame('Laravel', $links[0]['anchor_text']);
    }

    public function test_insert_does_not_create_nested_links(): void
    {
        $md = 'I love [Laravel](https://laravel.com) framework';

        $result = BardLinkInserter::insertLinkIntoMarkdown(
            $md,
            'Laravel',
            'https://other.com',
        );

        $this->assertNull($result, 'Should not link text that is already linked');
    }

    public function test_word_boundary_prevents_partial_word_linking(): void
    {
        // "WAR" should not match inside "beWARe"
        $this->assertNull(BardLinkInserter::insertLinkIntoMarkdown('We should beware of this', 'WAR', 'https://example.com'));

        // "WAR" should match as standalone word
        $this->assertNotNull(BardLinkInserter::insertLinkIntoMarkdown('The WAR continues', 'WAR', 'https://example.com'));
    }

    // --- Auto-Link Rule Consistency ---

    public function test_auto_link_rule_roundtrip_preserves_data(): void
    {
        $rule = \Inkline\Linkwise\AutoLink\AutoLinkRule::create([
            'keyword' => 'Test Keyword',
            'url' => 'https://example.com',
            'once_per_post' => false,
            'skip_if_exists' => true,
            'case_sensitive' => true,
            'collections' => ['blog'],
        ]);

        $restored = \Inkline\Linkwise\AutoLink\AutoLinkRule::fromArray($rule->toArray());

        $this->assertSame($rule->keyword, $restored->keyword);
        $this->assertSame($rule->url, $restored->url);
        $this->assertSame($rule->oncePerPost, $restored->oncePerPost);
        $this->assertSame($rule->skipIfExists, $restored->skipIfExists);
        $this->assertSame($rule->caseSensitive, $restored->caseSensitive);
        $this->assertSame($rule->collections, $restored->collections);
    }

    public function test_statamic_entry_url_detected_as_internal(): void
    {
        $rule = \Inkline\Linkwise\AutoLink\AutoLinkRule::create([
            'keyword' => 'Test',
            'url' => 'statamic://entry::abc-123',
        ]);

        $this->assertFalse($rule->isExternal());
        $this->assertSame('abc-123', $rule->targetEntryId);
    }

    public function test_http_url_detected_as_external(): void
    {
        $rule = \Inkline\Linkwise\AutoLink\AutoLinkRule::create([
            'keyword' => 'Test',
            'url' => 'https://example.com',
        ]);

        $this->assertTrue($rule->isExternal());
        $this->assertNull($rule->targetEntryId);
    }
}
