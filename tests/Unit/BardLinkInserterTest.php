<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BardLinkInserter;
use PHPUnit\Framework\TestCase;

class BardLinkInserterTest extends TestCase
{
    public function test_inserts_link_in_entire_text_node(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Redis Setup Guide'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result);
        $linked = $result[0]['content'][0];
        $this->assertSame('Redis Setup Guide', $linked['text']);
        $this->assertCount(1, $linked['marks']);
        $this->assertSame('link', $linked['marks'][0]['type']);
        $this->assertSame('statamic://entry::target-123', $linked['marks'][0]['attrs']['href']);
    }

    public function test_splits_text_node_in_middle(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Follow the Redis Setup Guide for best results.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result);
        $content = $result[0]['content'];
        $this->assertCount(3, $content);

        // Prefix
        $this->assertSame('Follow the ', $content[0]['text']);
        $this->assertArrayNotHasKey('marks', $content[0]);

        // Linked anchor
        $this->assertSame('Redis Setup Guide', $content[1]['text']);
        $this->assertSame('link', $content[1]['marks'][0]['type']);

        // Suffix
        $this->assertSame(' for best results.', $content[2]['text']);
    }

    public function test_splits_at_start_of_node(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Redis Setup Guide is great.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result);
        $content = $result[0]['content'];
        $this->assertCount(2, $content);
        $this->assertSame('Redis Setup Guide', $content[0]['text']);
        $this->assertNotEmpty($content[0]['marks']);
        $this->assertSame(' is great.', $content[1]['text']);
    }

    public function test_splits_at_end_of_node(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Read the Redis Setup Guide'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result);
        $content = $result[0]['content'];
        $this->assertCount(2, $content);
        $this->assertSame('Read the ', $content[0]['text']);
        $this->assertSame('Redis Setup Guide', $content[1]['text']);
        $this->assertNotEmpty($content[1]['marks']);
    }

    public function test_preserves_existing_marks(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Follow the Redis Setup Guide here.',
                        'marks' => [['type' => 'bold']],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result);
        $content = $result[0]['content'];

        // Prefix keeps bold only
        $this->assertSame([['type' => 'bold']], $content[0]['marks']);

        // Anchor has bold + link
        $this->assertCount(2, $content[1]['marks']);
        $this->assertSame('bold', $content[1]['marks'][0]['type']);
        $this->assertSame('link', $content[1]['marks'][1]['type']);

        // Suffix keeps bold only
        $this->assertSame([['type' => 'bold']], $content[2]['marks']);
    }

    public function test_replaces_different_link_on_already_linked_text(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Redis Setup Guide',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result, 'Should replace existing external link with internal link');
        $linked = $result[0]['content'][0];
        $this->assertCount(1, $linked['marks'], 'Should have exactly 1 link mark');
        $this->assertSame('statamic://entry::target-123', $linked['marks'][0]['attrs']['href']);
    }

    public function test_case_insensitive_matching(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Check the redis setup guide now.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result);
        // Anchor text should use original case from content
        $linked = $result[0]['content'][1];
        $this->assertSame('redis setup guide', $linked['text']);
        $this->assertSame('link', $linked['marks'][0]['type']);
    }

    public function test_returns_null_when_not_found(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'This text has nothing relevant.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNull($result);
    }

    public function test_handles_empty_content(): void
    {
        $this->assertNull(BardLinkInserter::insertLink([], 'Redis', 'target-123'));
    }

    public function test_finds_in_heading(): void
    {
        $bard = [
            [
                'type' => 'heading',
                'attrs' => ['level' => 2],
                'content' => [
                    ['type' => 'text', 'text' => 'About Redis Setup Guide'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result);
        $this->assertSame('heading', $result[0]['type']);
    }

    public function test_replaces_existing_external_link_in_single_node(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'coffee',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://www.sueddeutsche.de']],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'coffee', 'target-123');

        $this->assertNotNull($result, 'Should replace existing external link');
        $linked = $result[0]['content'][0];
        $this->assertSame('coffee', $linked['text']);
        $this->assertCount(1, $linked['marks'], 'Should have exactly 1 link mark, not 2');
        $this->assertSame('statamic://entry::target-123', $linked['marks'][0]['attrs']['href']);
    }

    public function test_replaces_existing_link_across_nodes(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'We all love '],
                    [
                        'type' => 'text',
                        'text' => 'coffee',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com']],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'We all love coffee', 'target-123');

        $this->assertNotNull($result);
        $content = $result[0]['content'];

        // Both nodes should have the internal link, no external link
        foreach ($content as $node) {
            $linkMarks = array_filter($node['marks'] ?? [], fn ($m) => ($m['type'] ?? '') === 'link');
            if (! empty($linkMarks)) {
                $this->assertCount(1, $linkMarks, 'Each node should have exactly 1 link mark');
                $mark = array_values($linkMarks)[0];
                $this->assertSame('statamic://entry::target-123', $mark['attrs']['href']);
            }
        }
    }

    public function test_preserves_non_link_marks_when_replacing(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'coffee',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com']],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'coffee', 'target-123');

        $this->assertNotNull($result);
        $linked = $result[0]['content'][0];
        $this->assertCount(2, $linked['marks'], 'Should keep bold + new link');

        $markTypes = array_column($linked['marks'], 'type');
        $this->assertContains('bold', $markTypes);
        $this->assertContains('link', $markTypes);

        $linkMark = array_values(array_filter($linked['marks'], fn ($m) => $m['type'] === 'link'))[0];
        $this->assertSame('statamic://entry::target-123', $linkMark['attrs']['href']);
    }

    // ─── Word-boundary iteration: must skip invalid first matches ──────────────
    // These tests guard the bug where "database" failed because the first match
    // was inside "databases" (plural) and the inserter gave up instead of trying
    // the standalone "Database" later in the text.

    public function test_markdown_skips_first_match_inside_plural_and_links_standalone_word(): void
    {
        $md = "Compare flat-file CMS to databases. Then a Database CMS scales differently.";

        $result = BardLinkInserter::insertLinkIntoMarkdown($md, 'database', 'statamic://entry::target', false);

        $this->assertNotNull($result, 'Should iterate past "databases" and link the standalone "Database"');
        $this->assertStringContainsString('[Database](statamic://entry::target)', $result);
        $this->assertStringContainsString('databases', $result, 'The plural form must remain unlinked');
        // Only ONE link inserted (the first valid boundary match)
        $this->assertSame(1, substr_count($result, '(statamic://entry::target)'));
    }

    public function test_markdown_returns_null_when_keyword_only_appears_inside_other_words(): void
    {
        $md = "There are databases everywhere, plus database-driven systems and subdatabases.";
        // "database" only appears inside "databases", "database-driven" (hyphenated word boundary - actually ok), "subdatabases"
        // database-driven: 'database' followed by '-' which is NOT \p{L}\p{N} → boundary holds → would link
        // Let's use a stricter input where every match is glued to letters:
        $md = "We talk about databases, subdatabases, and predatabases.";

        $result = BardLinkInserter::insertLinkIntoMarkdown($md, 'database', 'statamic://entry::target', false);

        $this->assertNull($result, 'No standalone "database" exists — should return null');
    }

    public function test_markdown_already_linked_anywhere_returns_null(): void
    {
        $md = "Plain text about [database](statamic://entry::other) systems and a standalone Database here.";

        $result = BardLinkInserter::insertLinkIntoMarkdown($md, 'database', 'statamic://entry::target', false);

        $this->assertNull($result, 'Already-linked check is intentional: skip the rule for this entry');
    }

    public function test_bard_skips_first_match_inside_plural_and_links_standalone_word(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Flat-file CMS vs databases differ. A Database CMS scales differently.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'database', 'target-123');

        $this->assertNotNull($result, 'Bard inserter must iterate past "databases" too');
        // Confirm the standalone "Database" was wrapped, not "databases"
        $linkedSegment = collect($result[0]['content'])
            ->first(fn ($n) => isset($n['marks']) && ($n['marks'][0]['type'] ?? '') === 'link');
        $this->assertNotNull($linkedSegment);
        $this->assertSame('Database', $linkedSegment['text']);
    }

    // ─── Multi-Insert (oncePerPost=false) ─────────────────────────────────────

    public function test_markdown_multi_insert_wraps_every_valid_occurrence(): void
    {
        $md = "First database here. Then a Database mention. And another database too.";

        $result = BardLinkInserter::insertAllLinksIntoMarkdown($md, 'database', 'statamic://entry::t', false);

        $this->assertNotNull($result);
        $this->assertSame(3, substr_count($result, '(statamic://entry::t)'));
        // Standalone "database" → wrapped, "Database" preserves casing
        $this->assertStringContainsString('[Database](statamic://entry::t)', $result);
        $this->assertSame(2, substr_count($result, '[database](statamic://entry::t)'));
    }

    public function test_markdown_multi_insert_skips_occurrences_inside_plurals(): void
    {
        $md = "We have databases here and a real database too.";

        $result = BardLinkInserter::insertAllLinksIntoMarkdown($md, 'database', 'statamic://entry::t', false);

        $this->assertNotNull($result);
        $this->assertSame(1, substr_count($result, '(statamic://entry::t)'));
        $this->assertStringContainsString('databases', $result, 'Plural must remain unlinked');
    }

    public function test_markdown_multi_insert_preserves_existing_links_to_other_targets(): void
    {
        $md = "[database](statamic://entry::other) here. And another database here.";

        $result = BardLinkInserter::insertAllLinksIntoMarkdown($md, 'database', 'statamic://entry::t', false);

        $this->assertNotNull($result);
        // Existing other-target link must remain
        $this->assertStringContainsString('[database](statamic://entry::other)', $result);
        // Second occurrence gets the new link
        $this->assertSame(1, substr_count($result, '[database](statamic://entry::t)'));
    }

    public function test_markdown_multi_insert_returns_null_when_no_valid_match(): void
    {
        $md = "Only databases here, nothing standalone.";

        $result = BardLinkInserter::insertAllLinksIntoMarkdown($md, 'database', 'statamic://entry::t', false);

        $this->assertNull($result);
    }

    public function test_bard_multi_insert_wraps_every_match_in_paragraph(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'A database is one thing. Another database appears later. And a final database too.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertAllLinksWithHref($bard, 'database', 'statamic://entry::t', false);

        $this->assertNotNull($result);
        $children = $result[0]['content'];
        $linked = array_filter($children, fn ($c) => isset($c['marks']) && ($c['marks'][0]['type'] ?? '') === 'link');
        $this->assertCount(3, $linked, 'Should wrap all three valid occurrences');
        foreach ($linked as $node) {
            $this->assertSame('database', $node['text']);
            $this->assertSame('statamic://entry::t', $node['marks'][0]['attrs']['href']);
        }
    }

    public function test_bard_multi_insert_preserves_existing_link_marks(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'database',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::other']]],
                    ],
                    ['type' => 'text', 'text' => ' is fine, but a free-floating database is what we want.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertAllLinksWithHref($bard, 'database', 'statamic://entry::t', false);

        $this->assertNotNull($result);
        $children = $result[0]['content'];
        // The first node was already linked elsewhere — must not be touched.
        $this->assertSame('statamic://entry::other', $children[0]['marks'][0]['attrs']['href']);
        // The second occurrence in the unlinked text should be wrapped.
        $linkedToTarget = array_filter($children, fn ($c) => isset($c['marks']) && collect($c['marks'])->contains(fn ($m) => ($m['attrs']['href'] ?? '') === 'statamic://entry::t'));
        $this->assertCount(1, $linkedToTarget);
    }

    public function test_bard_multi_insert_returns_null_when_no_valid_match(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Only databases here. No standalone occurrence.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertAllLinksWithHref($bard, 'database', 'statamic://entry::t', false);

        $this->assertNull($result);
    }

    public function test_bard_multi_insert_recurses_into_nested_blocks(): void
    {
        $bard = [
            [
                'type' => 'blockquote',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'A nested database mention here.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Top-level database mention.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertAllLinksWithHref($bard, 'database', 'statamic://entry::t', false);

        $this->assertNotNull($result);
        // Both nested + top-level wrapped
        $nestedChildren = $result[0]['content'][0]['content'];
        $this->assertTrue(collect($nestedChildren)->contains(fn ($c) => isset($c['marks']) && ($c['marks'][0]['type'] ?? '') === 'link'));
        $topChildren = $result[1]['content'];
        $this->assertTrue(collect($topChildren)->contains(fn ($c) => isset($c['marks']) && ($c['marks'][0]['type'] ?? '') === 'link'));
    }

    public function test_does_not_link_text_inside_code_block(): void
    {
        // Code blocks must stay untouched — wrapping links around SQL/JS code
        // corrupts the rendered output and breaks Bard editors that don't have
        // the codeblock extension enabled.
        $bard = [
            [
                'type' => 'codeBlock',
                'attrs' => ['language' => 'sql'],
                'content' => [
                    ['type' => 'text', 'text' => 'SELECT * FROM database WHERE id = 1'],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'See the database docs above.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'database', 'target-id');

        $this->assertNotNull($result);
        // codeBlock content must be unchanged.
        $this->assertSame('SELECT * FROM database WHERE id = 1', $result[0]['content'][0]['text']);
        $this->assertArrayNotHasKey('marks', $result[0]['content'][0]);
        // The paragraph's "database" must be wrapped (the inserter still works for normal text).
        $linked = collect($result[1]['content'])->first(fn ($n) => isset($n['marks']));
        $this->assertNotNull($linked);
        $this->assertSame('database', $linked['text']);
    }

    public function test_multi_walker_does_not_link_text_inside_code_block(): void
    {
        $bard = [
            [
                'type' => 'codeBlock',
                'attrs' => ['language' => 'sql'],
                'content' => [
                    ['type' => 'text', 'text' => 'CREATE database test; database setup; database again;'],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'A database is also mentioned here.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertAllLinksWithHref($bard, 'database', 'statamic://entry::t', false);

        $this->assertNotNull($result);
        // codeBlock fully untouched.
        $this->assertSame('CREATE database test; database setup; database again;', $result[0]['content'][0]['text']);
        $this->assertArrayNotHasKey('marks', $result[0]['content'][0]);
        // Paragraph's match wrapped.
        $linked = collect($result[1]['content'])->filter(fn ($n) => isset($n['marks']));
        $this->assertCount(1, $linked);
    }

    public function test_skips_when_already_linked_to_same_target(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'coffee',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-123']],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'coffee', 'target-123');

        $this->assertNull($result, 'Should return null when already linked to same target');
    }
}
