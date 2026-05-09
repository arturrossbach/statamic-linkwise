<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;
use PHPUnit\Framework\TestCase;

class UrlReplacerTest extends TestCase
{
    protected UrlReplacer $replacer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replacer = new UrlReplacer;
    }

    // ─── Domain Extraction ─────────────────────────────────────────────────────

    public function test_extract_domain_with_https(): void
    {
        $this->assertSame('google.com', UrlReplacer::extractDomain('https://www.google.com'));
        $this->assertSame('google.com', UrlReplacer::extractDomain('https://google.com'));
        $this->assertSame('google.com', UrlReplacer::extractDomain('http://www.google.com'));
    }

    public function test_extract_domain_without_protocol(): void
    {
        $this->assertSame('google.com', UrlReplacer::extractDomain('www.google.com'));
        $this->assertSame('google.com', UrlReplacer::extractDomain('google.com'));
    }

    public function test_extract_domain_with_path(): void
    {
        $this->assertSame('google.com', UrlReplacer::extractDomain('https://www.google.com/search?q=test'));
        $this->assertSame('example.com', UrlReplacer::extractDomain('www.example.com/deep/path/page.html'));
    }

    public function test_extract_domain_with_port(): void
    {
        $this->assertSame('example.com', UrlReplacer::extractDomain('https://example.com:8080/path'));
    }

    public function test_extract_domain_subdomain(): void
    {
        $this->assertSame('sub.google.com', UrlReplacer::extractDomain('https://sub.google.com'));
        $this->assertSame('deep.sub.google.com', UrlReplacer::extractDomain('https://deep.sub.google.com'));
    }

    public function test_extract_domain_country_tld(): void
    {
        $this->assertSame('example.co.uk', UrlReplacer::extractDomain('https://example.co.uk/page'));
        $this->assertSame('spiegel.de', UrlReplacer::extractDomain('https://www.spiegel.de'));
    }

    public function test_extract_domain_ip_address(): void
    {
        $this->assertSame('192.168.1.1', UrlReplacer::extractDomain('https://192.168.1.1/admin'));
    }

    public function test_extract_domain_invalid(): void
    {
        $this->assertNull(UrlReplacer::extractDomain(''));
        $this->assertNull(UrlReplacer::extractDomain('/relative/path'));
        $this->assertNull(UrlReplacer::extractDomain('#anchor'));
    }

    // ─── href Matching ─────────────────────────────────────────────────────────

    public function test_domain_match_finds_all_variants(): void
    {
        // Search "google.com" should match all these:
        $this->assertTrue($this->replacer->hrefMatches('https://www.google.com', 'google.com'));
        $this->assertTrue($this->replacer->hrefMatches('http://google.com', 'google.com'));
        $this->assertTrue($this->replacer->hrefMatches('www.google.com', 'google.com'));
        $this->assertTrue($this->replacer->hrefMatches('https://google.com/page', 'google.com'));
        $this->assertTrue($this->replacer->hrefMatches('https://www.google.com/search?q=test', 'google.com'));
    }

    public function test_domain_match_with_www_search(): void
    {
        $this->assertTrue($this->replacer->hrefMatches('https://www.google.com', 'www.google.com'));
        $this->assertTrue($this->replacer->hrefMatches('https://google.com', 'www.google.com'));
        $this->assertTrue($this->replacer->hrefMatches('www.google.com', 'www.google.com'));
    }

    public function test_domain_match_with_full_url_search(): void
    {
        $this->assertTrue($this->replacer->hrefMatches('https://www.google.com', 'https://www.google.com'));
        $this->assertTrue($this->replacer->hrefMatches('www.google.com', 'https://www.google.com'));
    }

    public function test_domain_match_does_not_cross_unrelated_domains(): void
    {
        $this->assertFalse($this->replacer->hrefMatches('https://other.com', 'google.com'));
        $this->assertFalse($this->replacer->hrefMatches('https://notgoogle.com', 'google.com'));
    }

    public function test_subdomain_matched_by_parent_via_substring(): void
    {
        // Substring fallback: google.com matches inside sub.google.com
        $this->assertTrue($this->replacer->hrefMatches('https://sub.google.com', 'google.com'));
        // Exact subdomain match also works
        $this->assertTrue($this->replacer->hrefMatches('https://sub.google.com', 'sub.google.com'));
    }

    public function test_path_match(): void
    {
        // Search with path: only match URLs with that path prefix
        $this->assertTrue($this->replacer->hrefMatches('https://google.com/page', 'google.com/page'));
        $this->assertTrue($this->replacer->hrefMatches('https://google.com/page/sub', 'google.com/page'));
        $this->assertFalse($this->replacer->hrefMatches('https://google.com/other', 'google.com/page'));
    }

    public function test_skips_non_url_hrefs(): void
    {
        $this->assertFalse($this->replacer->hrefMatches('mailto:test@example.com', 'example.com'));
        $this->assertFalse($this->replacer->hrefMatches('tel:+49123', 'google.com'));
        $this->assertFalse($this->replacer->hrefMatches('#section', 'google.com'));
    }

    public function test_statamic_entry_match(): void
    {
        $this->assertTrue($this->replacer->hrefMatches('statamic://entry::abc-123', 'statamic://entry::abc-123'));
        $this->assertFalse($this->replacer->hrefMatches('statamic://entry::abc-123', 'statamic://entry::other'));
        $this->assertFalse($this->replacer->hrefMatches('statamic://entry::abc-123', 'google.com'));
    }

    // ─── Replacement URL Building ──────────────────────────────────────────────

    public function test_domain_replace_keeps_path(): void
    {
        $result = $this->replacer->buildReplacementUrl(
            'https://old.com/page?q=1',
            'old.com',
            'new.com',
        );
        $this->assertSame('https://new.com/page?q=1', $result);
    }

    public function test_domain_replace_keeps_fragment(): void
    {
        $result = $this->replacer->buildReplacementUrl(
            'https://old.com/page#section',
            'old.com',
            'new.com',
        );
        $this->assertSame('https://new.com/page#section', $result);
    }

    public function test_domain_replace_keeps_port(): void
    {
        $result = $this->replacer->buildReplacementUrl(
            'https://old.com:8080/path',
            'old.com',
            'new.com',
        );
        $this->assertSame('https://new.com:8080/path', $result);
    }

    public function test_domain_replace_preserves_www(): void
    {
        $result = $this->replacer->buildReplacementUrl(
            'https://www.old.com/page',
            'old.com',
            'www.new.com',
        );
        $this->assertSame('https://www.new.com/page', $result);
    }

    public function test_domain_replace_on_bare_url(): void
    {
        $result = $this->replacer->buildReplacementUrl(
            'www.old.com',
            'old.com',
            'new.com',
        );
        $this->assertSame('https://new.com', $result);
    }

    public function test_full_url_replace(): void
    {
        // When replace has a path, use the full replacement URL
        $result = $this->replacer->buildReplacementUrl(
            'https://old.com/page',
            'old.com',
            'https://new.com/different-page',
        );
        $this->assertSame('https://new.com/different-page', $result);
    }

    // ─── Bard: Find & Replace ──────────────────────────────────────────────────

    public function test_find_in_bard_by_domain(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'A ', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://www.google.com/page']]]],
                    ['type' => 'text', 'text' => 'B ', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'www.google.com']]]],
                    ['type' => 'text', 'text' => 'C ', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://other.com']]]],
                ],
            ],
        ];

        $results = $this->replacer->findInBard($bard, 'google.com');
        $this->assertCount(2, $results);
        $this->assertSame('https://www.google.com/page', $results[0]['matched_url']);
        $this->assertSame('www.google.com', $results[1]['matched_url']);
    }

    public function test_replace_in_bard_domain_swap(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Link',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com/page?q=1']]],
                    ],
                ],
            ],
        ];

        $result = $this->replacer->replaceInBard($bard, 'old.com', 'new.com');
        $this->assertSame('https://new.com/page?q=1', $result[0]['content'][0]['marks'][0]['attrs']['href']);
        $this->assertSame('Link', $result[0]['content'][0]['text']); // Anchor preserved
    }

    public function test_replace_preserves_other_marks(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Bold link',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'link', 'attrs' => ['href' => 'https://old.com']],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->replacer->replaceInBard($bard, 'old.com', 'new.com');
        $marks = $result[0]['content'][0]['marks'];
        $this->assertCount(2, $marks);
        $this->assertSame('bold', $marks[0]['type']);
    }

    public function test_replace_does_not_touch_other_domains(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Keep', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://keep.com']]]],
                    ['type' => 'text', 'text' => 'Change', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
                ],
            ],
        ];

        $result = $this->replacer->replaceInBard($bard, 'old.com', 'new.com');
        $this->assertSame('https://keep.com', $result[0]['content'][0]['marks'][0]['attrs']['href']);
        $this->assertStringContainsString('new.com', $result[0]['content'][1]['marks'][0]['attrs']['href']);
    }

    // ─── Markdown ──────────────────────────────────────────────────────────────

    public function test_find_in_markdown_by_domain(): void
    {
        $md = 'See [flowers](www.google.com) and [trees](https://www.google.com/page) and [other](https://other.com).';
        $results = $this->replacer->findInMarkdown($md, 'google.com');
        $this->assertCount(2, $results);
    }

    public function test_replace_in_markdown_domain_swap(): void
    {
        $md = 'See [link](https://old.com/page) here.';
        $result = $this->replacer->replaceInMarkdown($md, 'old.com', 'new.com');
        $this->assertSame('See [link](https://new.com/page) here.', $result);
    }

    public function test_replace_in_markdown_bare_url(): void
    {
        $md = 'See [flowers](www.google.com) here.';
        $result = $this->replacer->replaceInMarkdown($md, 'google.com', 'new.com');
        $this->assertSame('See [flowers](https://new.com) here.', $result);
    }

    public function test_replace_in_markdown_preserves_other_links(): void
    {
        $md = 'See [a](https://keep.com) and [b](https://old.com).';
        $result = $this->replacer->replaceInMarkdown($md, 'old.com', 'new.com');
        $this->assertStringContainsString('keep.com', $result);
        $this->assertStringNotContainsString('old.com', $result);
    }

    // ─── Replicator ────────────────────────────────────────────────────────────

    public function test_find_in_replicator(): void
    {
        $sets = [
            [
                'type' => 'article',
                'id' => 'set-1',
                'enabled' => true,
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'www.google.com']]]],
                        ],
                    ],
                ],
            ],
        ];

        $results = $this->replacer->findInReplicator($sets, 'google.com');
        $this->assertCount(1, $results);
    }

    public function test_replace_in_replicator(): void
    {
        $sets = [
            [
                'type' => 'article',
                'id' => 'set-1',
                'enabled' => true,
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com/page']]]],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->replacer->replaceInReplicator($sets, 'old.com', 'new.com');
        $this->assertSame('https://new.com/page', $result[0]['content'][0]['content'][0]['marks'][0]['attrs']['href']);
    }

    // ─── Edge Cases ────────────────────────────────────────────────────────────

    public function test_empty_content(): void
    {
        $this->assertEmpty($this->replacer->findInBard([], 'google.com'));
        $this->assertEmpty($this->replacer->findInMarkdown('', 'google.com'));
        $this->assertSame([], $this->replacer->replaceInBard([], 'old.com', 'new.com'));
        $this->assertSame('', $this->replacer->replaceInMarkdown('', 'old.com', 'new.com'));
    }

    public function test_text_without_links(): void
    {
        $bard = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'No links here.']]]];
        $this->assertEmpty($this->replacer->findInBard($bard, 'google.com'));
    }

    public function test_url_with_query_and_fragment(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/path?q=1&b=2#top']]]],
                ],
            ],
        ];

        $results = $this->replacer->findInBard($bard, 'example.com');
        $this->assertCount(1, $results);

        $replaced = $this->replacer->replaceInBard($bard, 'example.com', 'new.com');
        $this->assertSame('https://new.com/path?q=1&b=2#top', $replaced[0]['content'][0]['marks'][0]['attrs']['href']);
    }

    public function test_country_tld_domain(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://www.spiegel.de/article']]]],
                ],
            ],
        ];

        $results = $this->replacer->findInBard($bard, 'spiegel.de');
        $this->assertCount(1, $results);
    }

    // ─── Nth Replacement (targeted apply) ─────────────────────────────────────

    public function test_replace_only_first_occurrence_in_bard(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'First', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Second', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Third', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
            ]],
        ];

        // Replace only index 0 (first)
        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', 'https://new.com', 0);
        $this->assertTrue($replaced);
        $this->assertSame('https://new.com', $result[0]['content'][0]['marks'][0]['attrs']['href']);
        $this->assertSame('https://old.com', $result[1]['content'][0]['marks'][0]['attrs']['href']); // untouched
        $this->assertSame('https://old.com', $result[2]['content'][0]['marks'][0]['attrs']['href']); // untouched
    }

    public function test_replace_only_second_occurrence_in_bard(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'First', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Second', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
            ]],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', 'https://new.com', 1);
        $this->assertTrue($replaced);
        $this->assertSame('https://old.com', $result[0]['content'][0]['marks'][0]['attrs']['href']); // untouched
        $this->assertSame('https://new.com', $result[1]['content'][0]['marks'][0]['attrs']['href']); // replaced
    }

    public function test_replace_nth_out_of_bounds_returns_false_no_silent_first_match(): void
    {
        // Index miss (target=5 but only 1 link) MUST return false without
        // mutating anything. The earlier "Phase-2 fallback" silently picked
        // the first link with the same URL — fine for the simple case here,
        // catastrophic for the real one: user clicks Unlink on link X with
        // url Y, but link X was just moved/removed by another edit. With
        // fallback the system would silent-mutate link X' (a different link
        // that happens to share url Y). Removed 2026-05-09. The caller now
        // surfaces "position changed since scan, refresh required" and the
        // user verifies the refreshed scan before clicking again.
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Only', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
            ]],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', 'https://new.com', 5);
        $this->assertFalse($replaced, 'index miss must NOT silent-mutate any link');
        $this->assertSame('https://old.com', $result[0]['content'][0]['marks'][0]['attrs']['href'], 'original href must remain untouched');
    }

    public function test_replace_nth_out_of_bounds_with_unknown_url_does_nothing(): void
    {
        // Fallback should NOT touch anything if oldUrl doesn't exist anywhere.
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Only', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://other.com']]]],
            ]],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', 'https://new.com', 5);
        $this->assertFalse($replaced);
        $this->assertSame('https://other.com', $result[0]['content'][0]['marks'][0]['attrs']['href']);
    }

    public function test_replace_nth_in_markdown(): void
    {
        $md = 'See [first](https://old.com) and [second](https://old.com) here.';

        // Replace only second (index 1)
        [$result, $replaced] = $this->replacer->replaceNthInMarkdown($md, 'https://old.com', 'https://new.com', 1);
        $this->assertTrue($replaced);
        $this->assertSame('See [first](https://old.com) and [second](https://new.com) here.', $result);
    }

    public function test_replace_nth_in_markdown_first_only(): void
    {
        $md = 'See [first](https://old.com) and [second](https://old.com) here.';

        [$result, $replaced] = $this->replacer->replaceNthInMarkdown($md, 'https://old.com', 'https://new.com', 0);
        $this->assertTrue($replaced);
        $this->assertSame('See [first](https://new.com) and [second](https://old.com) here.', $result);
    }

    public function test_occurrence_index_in_find_results(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'A', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
                ['type' => 'text', 'text' => 'B', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://other.com']]]],
                ['type' => 'text', 'text' => 'C', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
            ]],
        ];

        $results = $this->replacer->findInBard($bard, 'old.com');
        $this->assertCount(2, $results);
        $this->assertSame(0, $results[0]['occurrence_index']);
        $this->assertSame(1, $results[1]['occurrence_index']);
    }

    public function test_statamic_entry_url(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Internal', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::old-id']]]],
                ],
            ],
        ];

        // Domain search should NOT match statamic URLs
        $this->assertEmpty($this->replacer->findInBard($bard, 'google.com'));

        // Exact statamic URL search should match
        $results = $this->replacer->findInBard($bard, 'statamic://entry::old-id');
        $this->assertCount(1, $results);
    }

    // ─── Exact Mode + Mode Isolation ────────────────────────────────────────

    public function test_exact_mode_literal_match(): void
    {
        $this->replacer->setMode('exact');
        $this->assertTrue($this->replacer->hrefMatches('https://example.com/page', 'https://example.com/page'));
        $this->assertFalse($this->replacer->hrefMatches('https://example.com/page2', 'https://example.com/page'));
    }

    public function test_mode_isolation_in_find(): void
    {
        // Same Bard content, different modes → different match counts.
        // This is the heart of R1: occurrence_index counters must align with
        // the mode used at find-time.
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'A', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://google.com/foo']]]],
                ['type' => 'text', 'text' => 'B', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://google.com/bar']]]],
                ['type' => 'text', 'text' => 'C', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://google.com/foo']]]],
            ]],
        ];

        $this->replacer->setMode('smart');
        $smartMatches = $this->replacer->findInBard($bard, 'google.com');
        $this->assertCount(3, $smartMatches);

        $this->replacer->setMode('exact');
        $exactMatches = $this->replacer->findInBard($bard, 'https://google.com/foo');
        // Exact mode must match only the 2 identical URLs, with their own counters
        $this->assertCount(2, $exactMatches);
        $this->assertSame(0, $exactMatches[0]['occurrence_index']);
        $this->assertSame(1, $exactMatches[1]['occurrence_index']);
    }
}
