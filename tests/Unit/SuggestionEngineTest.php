<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use Arturrossbach\Linkwise\Suggestions\SuggestionEngine;
use PHPUnit\Framework\TestCase;

class SuggestionEngineTest extends TestCase
{
    private SuggestionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        // Test engine has keyword matches enabled — most tests cover title/stem matches,
        // keyword-specific tests rely on this being on. Default (production) is off.
        $this->engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.4, enableKeywordMatches: true);
    }

    public function test_finds_exact_title_match(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup Guide',
                url: '/blog/redis-setup-guide',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'When configuring your server, follow the Redis Setup Guide for best results.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
        $this->assertSame(1.0, $suggestions[0]->score);
    }

    public function test_matches_case_insensitive(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Laravel Caching',
                url: '/blog/laravel-caching',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'You should implement laravel caching in your application.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
    }

    public function test_strips_leading_stopwords_for_matching(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'How to Configure Redis Caching',
                url: '/blog/redis-caching',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'Learn to configure Redis caching in your application for better performance.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
    }

    public function test_excludes_current_entry(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'This article covers Redis Setup.';
        $suggestions = $this->engine->suggest($text, $index, excludeEntryId: 'entry-1');

        $this->assertCount(0, $suggestions);
    }

    public function test_returns_multiple_suggestions_sorted_by_score(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
            'entry-2' => new EntryRecord(
                id: 'entry-2',
                title: 'How to Configure Redis Caching in Laravel',
                url: '/blog/redis-caching-laravel',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'After completing the Redis Setup, you can configure Redis caching in Laravel for optimal speed.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertGreaterThanOrEqual(2, count($suggestions));
        // Both entries should be found
        $entryIds = array_map(fn ($s) => $s->targetEntryId, $suggestions);
        $this->assertContains('entry-1', $entryIds);
        $this->assertContains('entry-2', $entryIds);
        // Redis Setup is exact 2/2 match (score 1.0)
        $redisSetup = array_values(array_filter($suggestions, fn ($s) => $s->targetEntryId === 'entry-1'));
        $this->assertSame(1.0, $redisSetup[0]->score);
    }

    public function test_respects_word_boundaries(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // "Redis Setupper" should NOT match "Redis Setup" (no word boundary after "Setup")
        $text = 'The Redis Setupper tool is useful.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(0, $suggestions);
    }

    public function test_does_not_match_single_words(): void
    {
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.4);

        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis',
                url: '/blog/redis',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'We use Redis for caching.';
        $suggestions = $engine->suggest($text, $index);

        $this->assertCount(0, $suggestions);
    }

    public function test_generate_match_phrases_full_title(): void
    {
        $phrases = $this->engine->generateMatchPhrases('Redis Setup Guide');

        $this->assertContains('redis setup guide', $phrases);
    }

    public function test_generate_match_phrases_strips_leading_stopwords(): void
    {
        $phrases = $this->engine->generateMatchPhrases('How to Configure Redis');

        $this->assertContains('configure redis', $phrases);
    }

    public function test_normalize_lowercases_and_strips_punctuation(): void
    {
        $this->assertSame('hello world', $this->engine->normalize('Hello, World!'));
        $this->assertSame('redis-cache setup', $this->engine->normalize('Redis-Cache Setup'));
    }

    public function test_handles_empty_text(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $suggestions = $this->engine->suggest('', $index);
        $this->assertCount(0, $suggestions);
    }

    public function test_handles_empty_index(): void
    {
        $suggestions = $this->engine->suggest('Some text about Redis caching.', []);
        $this->assertCount(0, $suggestions);
    }

    public function test_excludes_already_linked_entries(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
            'entry-2' => new EntryRecord(
                id: 'entry-2',
                title: 'Laravel Caching',
                url: '/blog/laravel-caching',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
            'current' => new EntryRecord(
                id: 'current',
                title: 'My Article',
                url: '/blog/my-article',
                collection: 'articles',
                text: '',
                outboundLinks: ['entry-1'], // Already links to entry-1
            ),
        ];

        $text = 'Follow the Redis Setup guide and configure Laravel Caching for speed.';
        $suggestions = $this->engine->suggest($text, $index, excludeEntryId: 'current');

        // entry-1 (Redis Setup) should be excluded because current already links to it
        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-2', $suggestions[0]->targetEntryId);
    }

    public function test_excludes_explicitly_linked_ids(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'Follow the Redis Setup guide.';
        $suggestions = $this->engine->suggest($text, $index, alreadyLinkedIds: ['entry-1']);

        $this->assertCount(0, $suggestions);
    }

    public function test_suggestion_includes_sentence_context(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup Guide',
                url: '/blog/redis-setup-guide',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'When configuring your production server, you should follow the Redis Setup Guide for the best results in your deployment pipeline.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertNotEmpty($suggestions[0]->sentenceContext);
        $this->assertStringContainsString('Redis Setup Guide', $suggestions[0]->sentenceContext);
        $this->assertStringContainsString('follow the', $suggestions[0]->sentenceContext);
    }

    public function test_sentence_context_is_bounded(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $longText = str_repeat('word ', 200).'Redis Setup'.str_repeat(' word', 200);
        $suggestions = $this->engine->suggest($longText, $index);

        $this->assertCount(1, $suggestions);
        $this->assertLessThan(180, mb_strlen($suggestions[0]->sentenceContext));
        $this->assertTrue($suggestions[0]->contextTruncatedStart, 'Context should be truncated at start');
        $this->assertTrue($suggestions[0]->contextTruncatedEnd, 'Context should be truncated at end');
        $this->assertStringNotContainsString('...', $suggestions[0]->sentenceContext, 'Clean context should not contain ...');
    }

    public function test_anchor_never_spans_sentence_boundary(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Authentication Security',
                url: '/blog/auth-security',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // Text where "authentication" and "security" are in DIFFERENT sentences
        $text = 'Proper authentication ensures safety. Modern security requires multiple layers. Both matter.';
        $suggestions = $this->engine->suggest($text, $index);

        // Either no suggestion (correctly rejected), or any suggestion must not span sentences
        foreach ($suggestions as $s) {
            $this->assertDoesNotMatchRegularExpression(
                '/[.!?]\s/',
                $s->anchorText,
                "Anchor '{$s->anchorText}' spans a sentence boundary"
            );
        }

        // Also test with words in SAME sentence — should produce valid anchor
        $text2 = 'Modern authentication security requires multiple layers and careful planning for enterprise applications.';
        $suggestions2 = $this->engine->suggest($text2, $index);
        $this->assertNotEmpty($suggestions2, 'Should find match when words are in same sentence');
        foreach ($suggestions2 as $s) {
            $this->assertDoesNotMatchRegularExpression('/[.!?]\s/', $s->anchorText);
        }
    }

    public function test_anchor_max_length(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Web Development Best Practices Guide',
                url: '/blog/web-dev',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'The comprehensive web application development framework provides industry best practices and implementation guide for modern teams building scalable systems.';
        $suggestions = $this->engine->suggest($text, $index);

        // Any suggestion must respect the 80-char limit
        foreach ($suggestions as $s) {
            $this->assertLessThanOrEqual(
                80,
                mb_strlen($s->anchorText),
                "Anchor '{$s->anchorText}' exceeds 80 chars"
            );
        }
        $this->assertTrue(true, 'Max length constraint verified');
    }

    public function test_no_duplicate_targets_in_suggestions(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'CI/CD Pipelines for Web Applications',
                url: '/blog/ci-cd',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // Text contains "CI/CD pipelines" twice at different positions
        $text = 'Modern CI/CD pipelines automate testing. Later, CI/CD pipelines also handle deployment.';
        $suggestions = $this->engine->suggest($text, $index);

        // Same target should not appear twice
        $targetIds = array_map(fn ($s) => $s->targetEntryId, $suggestions);
        $this->assertSame(
            count(array_unique($targetIds)),
            count($targetIds),
            'Same target entry should not appear multiple times in suggestions'
        );
    }

    public function test_match_type_is_set(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup Guide',
                url: '/blog/redis',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'Learn how to configure a Redis Setup Guide for your application.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertNotEmpty($suggestions);
        foreach ($suggestions as $s) {
            $this->assertNotEmpty($s->matchType, 'matchType must be set');
            $this->assertContains($s->matchType, ['title', 'stem', 'keyword', 'custom']);
            $this->assertNotEmpty($s->matchReason, 'matchReason must be set');
        }
    }

    public function test_title_matches_never_capped(): void
    {
        // Create many entries that would generate keyword matches
        $index = [];
        for ($i = 0; $i < 60; $i++) {
            $index["entry-$i"] = new EntryRecord(
                id: "entry-$i",
                title: "Topic $i Guide",
                url: "/blog/topic-$i",
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['performance' => 0.5, 'guide' => 0.3],
            );
        }

        // Add a title-match entry
        $index['title-match'] = new EntryRecord(
            id: 'title-match',
            title: 'Performance Optimization',
            url: '/blog/perf',
            collection: 'articles',
            text: '',
            outboundLinks: [],
        );

        $text = 'This guide covers performance optimization techniques for modern applications.';
        $suggestions = $this->engine->suggest($text, $index, 'source-entry');

        // Title match should always be present regardless of cap
        $titleMatches = array_filter($suggestions, fn ($s) => $s->matchType === 'title');
        $this->assertNotEmpty($titleMatches, 'Title matches must never be capped');

        $perfMatch = array_filter($suggestions, fn ($s) => $s->targetEntryId === 'title-match');
        $this->assertNotEmpty($perfMatch, 'Performance Optimization title match must be present');
    }

    public function test_german_title_matching(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Laravel Caching einrichten',
                url: '/blog/laravel-caching-einrichten',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'In diesem Tutorial zeigen wir, wie Sie Laravel Caching einrichten können.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
    }

    // --- Keyword-based matching tests ---

    public function test_keyword_match_when_no_title_match(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Laravel Performance Optimization',
                url: '/blog/laravel-performance',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['laravel' => 0.5, 'perform' => 0.4, 'optim' => 0.3, 'cach' => 0.2, 'redi' => 0.1],
            ),
        ];

        // Text doesn't contain the title but contains keywords (stemmed: cach, perform, redi)
        $text = 'When building a Laravel application, you should focus on caching and performance improvements using Redis.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
        // Keyword-based scores are capped below title-match scores
        $this->assertLessThan(0.9, $suggestions[0]->score);
    }

    public function test_keyword_match_requires_minimum_overlap(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Advanced Database Design Patterns',
                url: '/blog/database-design',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['databas' => 0.5, 'design' => 0.4, 'pattern' => 0.3, 'normal' => 0.2, 'index' => 0.1],
            ),
        ];

        // Text with very little keyword overlap (only 1 low-scoring keyword)
        $text = 'The application uses simple indexing for search functionality in the frontend.';
        $suggestions = $this->engine->suggest($text, $index);

        // Should not match — too little overlap
        $this->assertCount(0, $suggestions);
    }

    public function test_title_match_scores_higher_than_keyword_match(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['redi' => 0.5, 'setup' => 0.4, 'configur' => 0.3],
            ),
            'entry-2' => new EntryRecord(
                id: 'entry-2',
                title: 'Laravel Performance Optimization',
                url: '/blog/laravel-performance',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['laravel' => 0.5, 'perform' => 0.4, 'optim' => 0.3, 'cach' => 0.2],
            ),
        ];

        $text = 'Follow the Redis Setup guide and consider caching and performance optimization for your Laravel app.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertGreaterThanOrEqual(2, count($suggestions));

        // Title match (Redis Setup) should rank higher
        $titleMatch = array_values(array_filter($suggestions, fn ($s) => $s->targetEntryId === 'entry-1'));
        $keywordMatch = array_values(array_filter($suggestions, fn ($s) => $s->targetEntryId === 'entry-2'));

        $this->assertNotEmpty($titleMatch);
        $this->assertNotEmpty($keywordMatch);
        $this->assertGreaterThan($keywordMatch[0]->score, $titleMatch[0]->score);
    }

    public function test_keyword_match_provides_context(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Database Migration Best Practices',
                url: '/blog/database-migration',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['databas' => 0.5, 'migrat' => 0.4, 'practic' => 0.2, 'schema' => 0.15],
            ),
        ];

        $text = 'When running a database migration, ensure your schema is properly versioned and backed up.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertNotEmpty($suggestions[0]->sentenceContext);
        $this->assertNotEmpty($suggestions[0]->anchorText);
    }

    public function test_keyword_match_skips_already_linked(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Caching Strategies Overview',
                url: '/blog/caching-strategies',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['cach' => 0.5, 'strategi' => 0.4, 'redi' => 0.3, 'memcach' => 0.2],
            ),
        ];

        $text = 'Use Redis caching strategies for better memcached integration.';
        $suggestions = $this->engine->suggest($text, $index, alreadyLinkedIds: ['entry-1']);

        $this->assertCount(0, $suggestions);
    }

    public function test_no_keywords_falls_back_to_title_only(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: [], // No keywords
            ),
        ];

        $text = 'Follow the Redis Setup guide for best results.';
        $suggestions = $this->engine->suggest($text, $index);

        // Should still match via title
        $this->assertCount(1, $suggestions);
        $this->assertSame(1.0, $suggestions[0]->score);
    }

    public function test_keyword_anchor_includes_neighbor_word(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Advanced Agent Orchestration Patterns',
                url: '/blog/agent-orchestration',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['agent' => 0.5, 'orchestr' => 0.4, 'pattern' => 0.3, 'subag' => 0.2],
            ),
        ];

        // "agent" and "orchestration" are not adjacent here, but "coding" is next to "agent"
        $text = 'Modern teams rely on coding agent frameworks and orchestration patterns for parallel development.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        // Anchor should be multi-word, not just "agent"
        $anchorWords = str_word_count($suggestions[0]->anchorText);
        $this->assertGreaterThanOrEqual(2, $anchorWords, 'Keyword anchor should be at least 2 words, got: "'.$suggestions[0]->anchorText.'"');
    }

    public function test_keyword_anchor_prefers_keyword_pair_over_neighbor(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Caching Strategy',
                url: '/blog/redis-caching',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['redi' => 0.5, 'cach' => 0.4, 'strategi' => 0.3],
            ),
        ];

        // "redis" stems to "redi", "caching" stems to "cach" — both match
        $text = 'Implement Redis caching for your production servers to improve response times.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertNotEmpty($suggestions[0]->anchorText);
    }

    public function test_keyword_anchor_skips_stopword_neighbors(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Performance Monitoring Tools',
                url: '/blog/performance-monitoring',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['perform' => 0.5, 'monitor' => 0.4, 'tool' => 0.3, 'metric' => 0.2],
            ),
        ];

        // "the" is a stopword before "performance", "your" after — should skip both
        // With generic anchor filter: single-word keyword anchors are also rejected
        $text = 'Improve the performance monitoring of your application by collecting metrics regularly.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        // Anchor should be multi-word and not contain stopwords at edges
        $anchor = mb_strtolower($suggestions[0]->anchorText);
        $this->assertStringNotContainsString('the ', $anchor);
        $this->assertGreaterThanOrEqual(2, str_word_count($anchor), 'Keyword anchors must be multi-word');
    }

    // ─── Stemmed Title Matching ────────────────────────────────────────

    public function test_stemmed_match_verb_inflection(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Migrating from WordPress to Statamic',
                url: '/blog/migrating-wp-to-statamic',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // "migrate" is a different verb form of "migrating" — should still match
        $text = 'If you want to migrate from WordPress to Statamic this is your chance!';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
        $this->assertStringContainsString('statamic', mb_strtolower($suggestions[0]->anchorText));
        $this->assertGreaterThanOrEqual(0.4, $suggestions[0]->score);
    }

    public function test_tier1_finds_2word_phrase_when_full_title_not_present(): void
    {
        // Title "Internal Linking Strategy for Better SEO" — the 6-word
        // contiguous title is NOT in source (the source skips "Strategy").
        // Tier 1 should fall back through n-grams and find "internal Linking"
        // (or another 2-word title phrase) as the cleanest available anchor.
        // Pre-fix this case landed in the unordered-stem fallback and produced
        // a noisy 7-word anchor; the n-gram path now stays at Tier 1 with a
        // tighter, SEO-friendlier match.
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Internal Linking Strategy for Better SEO',
                url: '/blog/internal-linking-seo',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'If you want to learn more about strategies for internal Linking for Better SEO then search our archive!';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
        $anchor = mb_strtolower($suggestions[0]->anchorText);
        // Either of the two clean 2-word matches is acceptable — both come
        // straight from the title and from the source.
        $this->assertTrue(
            str_contains($anchor, 'internal linking') || str_contains($anchor, 'better seo'),
            "Anchor should be a clean title phrase — got [$anchor]",
        );
        $this->assertGreaterThanOrEqual(0.4, $suggestions[0]->score);
    }

    public function test_long_title_2word_phrase_match_scores_above_threshold(): void
    {
        // Regression test: pre-fix, a 24-word descriptive title made any
        // 2-word match score below min_score (e.g. 2/24 = 0.083 << 0.4).
        // Long news-style titles became structurally invisible. The
        // absolute-vs-ratio max formula gives 2-word literal matches a
        // floor of 0.5 regardless of title length.
        $longTitle = 'WAR RAGES ON Houthis launch missile at Israel and join Iran war against Trump after over two dozen US troops wounded in Saudi strikes';
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: $longTitle,
                url: '/blog/war-rages-on',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $source = 'and let us be honest: WAR RAGES ON in israel today!';
        $suggestions = $this->engine->suggest($source, $index);

        $this->assertNotEmpty($suggestions, 'Long title should still surface 2+ word literal matches.');
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
        $this->assertGreaterThanOrEqual(0.4, $suggestions[0]->score);
    }

    public function test_stemmed_match_plural(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Deploying Laravel Applications',
                url: '/blog/deploying-laravel',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // "deployed" + "application" vs title "Deploying" + "Applications"
        $text = 'We deployed the Laravel application to production yesterday.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
        $this->assertGreaterThan(0.5, $suggestions[0]->score);
    }

    public function test_stemmed_match_does_not_break_word_boundaries(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Redis Setup',
                url: '/blog/redis-setup',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // "Setupper" should not match even via stemming
        $text = 'The Redis Setupper tool is a completely different thing.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(0, $suggestions);
    }

    public function test_stemmed_match_scores_lower_than_exact(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Configuring Redis Clusters',
                url: '/blog/redis-clusters',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // Exact match
        $exactText = 'A guide to Configuring Redis Clusters for production.';
        $exact = $this->engine->suggest($exactText, $index);

        // Stemmed match ("configured" vs "configuring" — regular verb, same stem "configur")
        $stemmedText = 'We configured Redis Clusters for our production environment.';
        $stemmed = $this->engine->suggest($stemmedText, $index);

        $this->assertCount(1, $exact);
        $this->assertCount(1, $stemmed);
        // Stemmed should score lower (0.9x penalty)
        $this->assertGreaterThan($stemmed[0]->score, $exact[0]->score);
    }

    public function test_stemmed_match_context_contains_full_anchor(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Migrating from WordPress to Statamic',
                url: '/blog/migrating',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'The best way to migrate from WordPress to Statamic is with a proper plan.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        // The anchor text must appear fully within the context
        $this->assertStringContainsString(
            mb_strtolower($suggestions[0]->anchorText),
            mb_strtolower($suggestions[0]->sentenceContext),
        );
    }

    public function test_exact_match_preferred_over_stemmed(): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: 'Testing Laravel Applications',
                url: '/blog/testing-laravel',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        // Exact title appears in text — should not get stem discount
        $text = 'A guide to Testing Laravel Applications in CI pipelines.';
        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(1, $suggestions);
        $this->assertSame(1.0, $suggestions[0]->score);
    }

    // ─── Real-World Title Variation Tests ──────────────────────────────
    // Based on actual entries in the test corpus.
    // Each test verifies that natural language variations of a title still match.

    #[\PHPUnit\Framework\Attributes\DataProvider('titleVariationProvider')]
    public function test_title_variation_matches(string $title, string $textVariation, string $description): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: $title,
                url: '/test',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $suggestions = $this->engine->suggest($textVariation, $index);

        $this->assertCount(1, $suggestions, "Failed: {$description}\nTitle: {$title}\nText: {$textVariation}");
        $this->assertSame('entry-1', $suggestions[0]->targetEntryId);
        $this->assertGreaterThanOrEqual(0.4, $suggestions[0]->score);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('titleNoMatchProvider')]
    public function test_title_variation_does_not_match(string $title, string $text, string $description): void
    {
        $index = [
            'entry-1' => new EntryRecord(
                id: 'entry-1',
                title: $title,
                url: '/test',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $suggestions = $this->engine->suggest($text, $index);

        $this->assertCount(0, $suggestions, "Should NOT match: {$description}\nTitle: {$title}\nText: {$text}");
    }

    public static function titleVariationProvider(): array
    {
        return [
            // ─── Exact matches ─────────────────────────────────────
            'exact: Laravel' => [
                'Getting Started with Laravel',
                'You should read Getting Started with Laravel before diving in.',
                'Exact title in text',
            ],
            'exact: Redis' => [
                'Redis Caching Strategies for Web Applications',
                'Our Redis Caching Strategies for Web Applications guide covers everything.',
                'Exact title in text',
            ],

            // ─── Case variations ───────────────────────────────────
            'case: all lowercase' => [
                'Vue.js Component Architecture',
                'We documented our vue.js component architecture decisions.',
                'All lowercase should still match',
            ],

            // ─── Verb inflections (stemming) ───────────────────────
            'stem: Building→built' => [
                'Building APIs with Laravel',
                'We have been building APIs with Laravel for three years.',
                'Present participle matches gerund title',
            ],
            'stem: Testing→tested' => [
                'Testing Strategies for Web Applications',
                'We tested strategies for web applications thoroughly.',
                'Past tense matches gerund title',
            ],
            'stem: Migrating→migrate' => [
                'Migrating from WordPress to Statamic',
                'Learn how to migrate from WordPress to Statamic safely.',
                'Base verb matches gerund title',
            ],
            'stem: Optimization→optimized' => [
                'Understanding PHP Performance Optimization',
                'After understanding PHP performance optimization we optimized the queries.',
                'Exact title with surrounding text',
            ],

            // ─── Word order variations (unordered matching) ────────
            'reorder: SEO linking' => [
                'Internal Linking Strategy for Better SEO',
                'Our strategies for internal linking and better SEO drove organic traffic.',
                'Reordered title words with filler',
            ],
            'reorder: content writing' => [
                'Content Writing for Technical Audiences',
                'Writing technical content for developer audiences requires precision.',
                'Significant word reorder',
            ],
            'reorder: keyword research' => [
                'Keyword Research for Content Strategy',
                'A content strategy starts with solid keyword research.',
                'Completely reversed order',
            ],
            'reorder: link building' => [
                'Link Building Strategies That Actually Work',
                'Strategies that work for building quality links are rare.',
                'Heavy reorder with filler words',
            ],
            'reorder: image optimization' => [
                'Image Optimization for the Web',
                'Optimizing images on the web improves performance significantly.',
                'Verb inflection + reorder',
            ],

            // ─── Filler words between title words ──────────────────
            'filler: Laravel APIs' => [
                'Building APIs with Laravel',
                'Our team is actively building modern REST APIs using the popular Laravel framework.',
                'Filler words between each title word',
            ],
            'filler: Docker PHP' => [
                'Docker for PHP Development',
                'Docker has become essential for modern PHP-based web development.',
                'Filler and compound word',
            ],
            'filler: database design' => [
                'Database Design Best Practices',
                'These database design and modeling best practices will help your project.',
                'Filler "and modeling" between words',
            ],

            // ─── Partial title matches (sub-phrases) ──────────────
            'partial: PHP Performance' => [
                'Understanding PHP Performance Optimization',
                'PHP performance optimization is critical for large apps.',
                'Dropped leading word "Understanding"',
            ],
            'partial: CMS Modern' => [
                'Statamic CMS for Modern Websites',
                'Statamic CMS for modern websites offers flat-file simplicity.',
                'Exact after stopword variations',
            ],
            'partial: Server Security' => [
                'Server Security for Web Applications',
                'Server security for web applications starts with the basics.',
                'Exact match including stopwords',
            ],

            // ─── Plural/singular variations ────────────────────────
            'plural: Strategy→Strategies' => [
                'Redis Caching Strategies for Web Applications',
                'One popular Redis caching strategy for web applications is cache-aside.',
                'Singular form of plural title word',
            ],
            'plural: Application→Applications' => [
                'CI/CD Pipelines for Web Applications',
                'Every web application needs a CI/CD pipeline.',
                'Singular + dropped plural',
            ],

            // ─── Mixed: inflection + reorder + filler ──────────────
            'mixed: analytics measuring' => [
                'Analytics and Measuring Content Performance',
                'Measuring your content performance with advanced analytics tools reveals insights.',
                'Reorder + filler "tools reveals insights"',
            ],
            'mixed: email marketing' => [
                'Email Marketing Integration',
                'Integrating email marketing into your CMS workflow saves time.',
                'Verb form "integrating" + reorder',
            ],
            'mixed: headless CMS' => [
                'Headless CMS Architecture',
                'The architecture of a headless CMS differs from traditional setups.',
                'Reorder with "of a" filler',
            ],
            'mixed: JS build tools' => [
                'JavaScript Build Tools Comparison',
                'Comparing popular JavaScript build tools reveals surprising differences.',
                'Verb form + reorder',
            ],
        ];
    }

    public static function titleNoMatchProvider(): array
    {
        return [
            // ─── Too few matching words ────────────────────────────
            'insufficient: single word' => [
                'Redis Caching Strategies for Web Applications',
                'Redis is fast.',
                'Only 1 of 4 content words — too few to match',
            ],
            'insufficient: unrelated' => [
                'Docker for PHP Development',
                'Python machine learning models need GPU acceleration.',
                'Completely unrelated content',
            ],

            // ─── Word boundary safety ──────────────────────────────
            'boundary: partial word' => [
                'Redis Setup',
                'The Redis Setupper tool is different.',
                'Partial word match should NOT trigger',
            ],

            // ─── Different meaning despite shared words ────────────
            'meaning: web vs spider web' => [
                'Server Security for Web Applications',
                'The spider built a beautiful web in the garden application shed.',
                'Same words but completely different context',
            ],

            // ─── Words too far apart (multi-sentence span) ─────────
            'span: building...Laravel across sentences' => [
                'Building APIs with Laravel',
                "Content modeling is the foundation. Define your content types before building templates. Statamic's blueprints provide flexible content modeling capabilities. Coffee is awesome. Laravel Statamic CMS is also awesome!",
                'Title words scattered across multiple sentences — too far apart to be a coherent reference',
            ],
        ];
    }

    // ─── Outbound Links Exclusion Tests ────────────────────────────

    public function test_already_linked_entries_are_excluded_from_suggestions(): void
    {
        $index = [
            'target-1' => new EntryRecord(
                id: 'target-1',
                title: 'Redis Caching',
                url: '/blog/redis-caching',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
            'target-2' => new EntryRecord(
                id: 'target-2',
                title: 'Database Optimization',
                url: '/blog/database-optimization',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'Learn about Redis Caching and Database Optimization for better performance.';

        // Without any existing links → both should be suggested
        $suggestions = $this->engine->suggest($text, $index, 'source-1', []);
        $targetIds = array_map(fn ($s) => $s->targetEntryId, $suggestions);
        $this->assertContains('target-1', $targetIds, 'target-1 should be suggested when not linked');
        $this->assertContains('target-2', $targetIds, 'target-2 should be suggested when not linked');

        // With target-1 already linked → only target-2 should be suggested
        $suggestions = $this->engine->suggest($text, $index, 'source-1', ['target-1']);
        $targetIds = array_map(fn ($s) => $s->targetEntryId, $suggestions);
        $this->assertNotContains('target-1', $targetIds, 'target-1 should NOT be suggested when already linked');
        $this->assertContains('target-2', $targetIds, 'target-2 should still be suggested');
    }

    public function test_unlinked_entries_reappear_as_suggestions(): void
    {
        $index = [
            'target-1' => new EntryRecord(
                id: 'target-1',
                title: 'Redis Caching',
                url: '/blog/redis-caching',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'Learn about Redis Caching for better application performance.';

        // Initially linked → no suggestion
        $suggestionsLinked = $this->engine->suggest($text, $index, 'source-1', ['target-1']);
        $targetIdsLinked = array_map(fn ($s) => $s->targetEntryId, $suggestionsLinked);
        $this->assertNotContains('target-1', $targetIdsLinked, 'Linked entry must not appear as suggestion');

        // After unlink (empty outboundLinks) → suggestion reappears
        $suggestionsUnlinked = $this->engine->suggest($text, $index, 'source-1', []);
        $targetIdsUnlinked = array_map(fn ($s) => $s->targetEntryId, $suggestionsUnlinked);
        $this->assertContains('target-1', $targetIdsUnlinked, 'Unlinked entry must reappear as suggestion');
    }

    public function test_partially_unlinked_entries_only_unlinked_reappear(): void
    {
        $index = [
            'target-1' => new EntryRecord(
                id: 'target-1',
                title: 'Redis Caching',
                url: '/blog/redis-caching',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
            'target-2' => new EntryRecord(
                id: 'target-2',
                title: 'Database Optimization',
                url: '/blog/database-optimization',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
            'target-3' => new EntryRecord(
                id: 'target-3',
                title: 'Performance Monitoring',
                url: '/blog/performance-monitoring',
                collection: 'articles',
                text: '',
                outboundLinks: [],
            ),
        ];

        $text = 'Learn about Redis Caching, Database Optimization, and Performance Monitoring for production systems.';

        // All three linked → none suggested
        $suggestions = $this->engine->suggest($text, $index, 'source-1', ['target-1', 'target-2', 'target-3']);
        $targetIds = array_map(fn ($s) => $s->targetEntryId, $suggestions);
        $this->assertNotContains('target-1', $targetIds);
        $this->assertNotContains('target-2', $targetIds);
        $this->assertNotContains('target-3', $targetIds);

        // Unlink target-1 and target-3, keep target-2 linked
        $suggestions = $this->engine->suggest($text, $index, 'source-1', ['target-2']);
        $targetIds = array_map(fn ($s) => $s->targetEntryId, $suggestions);
        $this->assertContains('target-1', $targetIds, 'Unlinked target-1 must reappear');
        $this->assertNotContains('target-2', $targetIds, 'Still-linked target-2 must stay excluded');
        $this->assertContains('target-3', $targetIds, 'Unlinked target-3 must reappear');
    }

    // ─── Keyword Matches are Opt-In ─────────────────────────────────────

    public function test_keyword_matches_are_disabled_by_default(): void
    {
        // Engine with default config (no enableKeywordMatches) → no keyword matches
        $engine = new SuggestionEngine(minPhraseWords: 2, minScore: 0.4);

        $index = [
            'target-1' => new EntryRecord(
                id: 'target-1',
                title: 'Zzzzz Unusual Title',
                url: '/blog/x',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['databas' => 0.8, 'architectur' => 0.7],
            ),
        ];

        $text = 'Good database architecture requires careful planning.';
        $suggestions = $engine->suggest($text, $index, 'source-1', []);

        // No title/stem match possible (title has no overlap), keyword match disabled by default
        $this->assertEmpty($suggestions, 'Keyword matches must be opt-in');
    }

    public function test_keyword_matches_work_when_explicitly_enabled(): void
    {
        $engine = new SuggestionEngine(
            minPhraseWords: 2,
            minScore: 0.4,
            minKeywordScore: 0.30,
            enableKeywordMatches: true,
        );

        $index = [
            'target-1' => new EntryRecord(
                id: 'target-1',
                title: 'Zzzzz Unusual Title',
                url: '/blog/x',
                collection: 'articles',
                text: '',
                outboundLinks: [],
                keywords: ['databas' => 0.8, 'architectur' => 0.7],
            ),
        ];

        $text = 'Good database architecture requires careful planning.';
        $suggestions = $engine->suggest($text, $index, 'source-1', []);

        $this->assertNotEmpty($suggestions, 'Keyword matches should work when enabled');
        $this->assertSame('target-1', $suggestions[0]->targetEntryId);
        $this->assertSame('keyword', $suggestions[0]->matchType);
    }
}
