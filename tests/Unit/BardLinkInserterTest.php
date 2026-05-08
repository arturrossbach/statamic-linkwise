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

    // ─────────────────────────────────────────────────────────────────────
    // Bug B regression suite — partial-overlap with an existing link must
    // NOT split the linked text node and tear the original link apart.
    //
    // Real-data trigger 2026-05-08: source entry had
    //   text: "Brauner-Zucker-Speck-Kekse" (single text node, linked to X)
    // An Outbound suggestion proposed anchor "Brauner" → entry Y.
    // splitSingleNode produced
    //   "Brauner" (link Y) + "-Zucker-Speck-Kekse" (link X)
    // Original phrase ended up half-pointing at the wrong target. The
    // multi-walker (findAndLinkAllInChildren) had skipped pre-linked
    // nodes since day one — single walker now matches that contract.
    //
    // Fully-covered linked nodes (URL-upgrade case, anchor === entire
    // linked text) STAY replaceable — that is intentional and used by
    // URL-Changer. See test_replaces_different_link_on_already_linked_text
    // and the dedicated multi-node fully-covered test below.
    // ─────────────────────────────────────────────────────────────────────

    public function test_bugB_skips_anchor_inside_partially_covered_linked_phrase(): void
    {
        // The original user-reported case in minimal form: anchor is a
        // strict prefix of a linked hyphen-word, no other valid occurrence
        // exists → return null, do NOT tear the original link.
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Weiter mit '],
                    [
                        'type' => 'text',
                        'text' => 'Brauner-Zucker-Speck-Kekse',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::existing-cookie']],
                        ],
                    ],
                    ['type' => 'text', 'text' => '.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Brauner', 'pasta-salbeibutter');

        $this->assertNull(
            $result,
            'Anchor inside a partially-covered linked phrase must be skipped — splitting would tear the existing link in half',
        );
    }

    public function test_bugB_falls_back_to_later_unlinked_occurrence(): void
    {
        // Crucial counterpart: skip-and-continue, NOT abort. If the FIRST
        // occurrence sits inside a linked phrase but a LATER plain
        // occurrence exists, the walker must find that one. Returning
        // null after the partial-overlap skip would leave a perfectly
        // valid suggestion unfulfilled.
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Weiter mit '],
                    [
                        'type' => 'text',
                        'text' => 'Brauner-Zucker-Speck-Kekse',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::existing']],
                        ],
                    ],
                    ['type' => 'text', 'text' => '. Später kam ein Brauner Bär vorbei.'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Brauner', 'pasta-salbeibutter');

        $this->assertNotNull($result, 'Walker must continue past the linked occurrence and find the plain "Brauner" later in the paragraph');
        $children = $result[0]['content'];

        // Linked hyphen-word must be untouched.
        $linkedNode = $children[1];
        $this->assertSame('Brauner-Zucker-Speck-Kekse', $linkedNode['text']);
        $this->assertCount(1, $linkedNode['marks']);
        $this->assertSame('statamic://entry::existing', $linkedNode['marks'][0]['attrs']['href']);

        // Walk the children to find a node with text === 'Brauner' that is
        // NOT the start of the original linked phrase. Asserting via
        // structure-position is fragile because the splits can produce
        // 4-5 nodes depending on how the suffix gets carved.
        $brauner = null;
        foreach ($children as $c) {
            if (($c['text'] ?? '') === 'Brauner' && empty(array_filter(
                $c['marks'] ?? [],
                fn ($m) => ($m['attrs']['href'] ?? '') === 'statamic://entry::existing',
            ))) {
                $brauner = $c;
                break;
            }
        }
        $this->assertNotNull($brauner, 'Plain "Brauner" should be split out and linked');
        $this->assertNotEmpty($brauner['marks'] ?? []);
        $this->assertSame('statamic://entry::pasta-salbeibutter', $brauner['marks'][0]['attrs']['href']);
    }

    public function test_bugB_skips_when_anchor_crosses_into_linked_node(): void
    {
        // Multi-node match where the END falls inside a linked node.
        // Anchor "Brauner-Zucker" starts in node[1] (plain "Brauner") and
        // ends inside node[2] (linked "-Zucker-Speck-Kekse"). The link
        // node is partially covered → skip.
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Mit '],
                    ['type' => 'text', 'text' => 'Brauner'],
                    [
                        'type' => 'text',
                        'text' => '-Zucker-Speck-Kekse',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::existing']],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Brauner-Zucker', 'other-target');

        $this->assertNull($result, 'Anchor that crosses into a linked node must be skipped');
    }

    public function test_bugB_skips_when_anchor_crosses_out_of_linked_node(): void
    {
        // Mirror case: match starts inside a linked node, ends in plain
        // text. Linked node is partially covered → skip.
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Brauner-Zucker',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::existing']],
                        ],
                    ],
                    ['type' => 'text', 'text' => ' und Mehr'],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Zucker und Mehr', 'other-target');

        $this->assertNull($result, 'Anchor that starts inside a linked node and crosses out must be skipped');
    }

    public function test_bugB_fully_covers_linked_node_still_replaces(): void
    {
        // Preserves the URL-upgrade behavior: anchor === full linked text
        // → existing different-href link mark is replaced with the new one.
        // See also test_replaces_different_link_on_already_linked_text and
        // test_replaces_existing_external_link_in_single_node.
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Redis Setup Guide',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com/old']],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLink($bard, 'Redis Setup Guide', 'target-123');

        $this->assertNotNull($result, 'Fully-covered different-href link should still be replaceable');
        $this->assertSame(
            'statamic://entry::target-123',
            $result[0]['content'][0]['marks'][0]['attrs']['href'],
        );
    }

    public function test_bugB_invariant_existing_link_text_stays_linked(): void
    {
        // Domain invariant for any single-walker run: text spans that
        // were inside a link mark before the call must STILL be inside
        // a link mark after the call. This guards the broader Bug B
        // class — any future code path that destructures text nodes
        // around an existing link must preserve coverage.
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Mit '],
                    [
                        'type' => 'text',
                        'text' => 'Brauner-Zucker-Speck-Kekse',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::existing']],
                        ],
                    ],
                    ['type' => 'text', 'text' => '. Auch ein Brauner Bär kam vorbei.'],
                ],
            ],
        ];

        $beforeLinkedText = self::concatLinkedText($bard);
        $result = BardLinkInserter::insertLink($bard, 'Brauner', 'target-123');
        // Either the walker found a plain occurrence (modified tree) or
        // it didn't (null). Either way every original linked character
        // must still belong to a link mark in the post-state.
        $afterTree = $result ?? $bard;
        $afterLinkedText = self::concatLinkedText($afterTree);

        // The post-state's linked text is allowed to GROW (new link added)
        // but every character of the pre-state's linked text must still
        // appear as part of a linked span. Cheapest check: original linked
        // substring is still a contiguous substring of the linked text.
        $this->assertStringContainsString(
            $beforeLinkedText,
            $afterLinkedText,
            'Original linked text must remain entirely inside a link mark — no link node may be partially destroyed',
        );
    }

    /**
     * Helper: concat all text content carrying a link mark, in tree order.
     * Used by the invariant test above.
     */
    private static function concatLinkedText(array $tree): string
    {
        $buf = '';
        $walk = function ($nodes) use (&$walk, &$buf) {
            foreach ($nodes as $n) {
                if (! is_array($n)) continue;
                if (($n['type'] ?? '') === 'text') {
                    foreach ($n['marks'] ?? [] as $m) {
                        if (($m['type'] ?? '') === 'link') {
                            $buf .= $n['text'] ?? '';
                            break;
                        }
                    }
                } elseif (isset($n['content']) && is_array($n['content'])) {
                    $walk($n['content']);
                }
            }
        };
        $walk($tree);

        return $buf;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Retreat tests: V1 only writes to `bard` (structured) and `markdown`
    // (top-level). Plain-string fields (text/textarea) and plain-string
    // values nested in Replicator sets are NEVER touched, because they
    // render as plaintext per Statamic's contract — writing markdown-link
    // syntax there surfaces as visible literal `[anchor](url)`.
    // ─────────────────────────────────────────────────────────────────────

    public function test_replicator_walker_does_not_touch_plain_string_in_set(): void
    {
        // Simulates a Peak Card set: plain-string `heading` + Bard `text`.
        $sets = [
            [
                'type' => 'card',
                'id' => 'set-1',
                'heading' => 'Welcome to coffee',
                'text' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'We brew coffee daily.'],
                        ],
                    ],
                ],
            ],
        ];

        $reflection = new \ReflectionMethod(BardLinkInserter::class, 'processReplicatorWithHref');
        $reflection->setAccessible(true);

        $result = $reflection->invoke(null, $sets, 'coffee', 'statamic://entry::target-123');

        $this->assertNotNull($result, 'Bard fragment in set should still be linked');

        // `heading` (plain string) must remain literally unchanged
        $this->assertSame('Welcome to coffee', $result[0]['heading']);

        // `text` (Bard) must contain a link mark on the anchor
        $textNodes = $result[0]['text'][0]['content'];
        $hasLinkMark = false;
        foreach ($textNodes as $node) {
            foreach ($node['marks'] ?? [] as $mark) {
                if (($mark['type'] ?? '') === 'link') {
                    $hasLinkMark = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($hasLinkMark, 'Bard fragment must receive link mark');
    }

    public function test_replicator_walker_returns_null_when_only_plain_strings_present(): void
    {
        // A set with ONLY plain strings (no Bard, no nested replicator).
        // Result must be null — no insertion attempted, no markdown syntax
        // injected into plaintext fields.
        $sets = [
            [
                'type' => 'card',
                'id' => 'set-1',
                'heading' => 'Talk about coffee',
                'subtitle' => 'A coffee story',
                'cta_label' => 'Buy coffee now',
            ],
        ];

        $reflection = new \ReflectionMethod(BardLinkInserter::class, 'processReplicatorWithHref');
        $reflection->setAccessible(true);

        $result = $reflection->invoke(null, $sets, 'coffee', 'statamic://entry::target-123');

        $this->assertNull($result, 'Plain-string-only set must NOT be linked');
    }

    public function test_replicator_walker_recurses_into_nested_bard_via_grid(): void
    {
        // Nested replicator (e.g. a Grid containing Cards): outer set has
        // an inner array of sets. Bard inside the inner set should still
        // be reached.
        $sets = [
            [
                'type' => 'grid',
                'id' => 'set-outer',
                'cells' => [
                    [
                        'type' => 'card',
                        'id' => 'set-inner',
                        'body' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => 'About coffee culture.'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $reflection = new \ReflectionMethod(BardLinkInserter::class, 'processReplicatorWithHref');
        $reflection->setAccessible(true);

        $result = $reflection->invoke(null, $sets, 'coffee', 'statamic://entry::target-123');

        $this->assertNotNull($result, 'Nested Bard fragment should be linked');

        $innerBody = $result[0]['cells'][0]['body'][0]['content'];
        $hasLinkMark = collect($innerBody)->contains(
            fn ($n) => collect($n['marks'] ?? [])->contains(fn ($m) => ($m['type'] ?? '') === 'link')
        );
        $this->assertTrue($hasLinkMark, 'Nested Bard must receive link mark');
    }

    // ─────────────────────────────────────────────────────────────────────
    // insertLinkIntoMarkdown skipRanges: never insert INTO an existing
    // markdown link's anchor text OR URL portion. Without this guard,
    // multi-rule auto-linking on the same Markdown text recursively
    // corrupted content with nested `[[anchor]](url)](url)` syntax —
    // a real bug observed in prose-peak-test 2026-05-06.
    // ─────────────────────────────────────────────────────────────────────

    public function test_markdown_insert_does_not_match_inside_existing_anchor_text(): void
    {
        // Existing link wraps "Modern web development". A second auto-link
        // rule for "development" must NOT match here — the anchor lives
        // inside another anchor's text.
        $markdown = 'See [Modern web development](statamic://entry::abc-123) for details.';

        $result = BardLinkInserter::insertLinkIntoMarkdown(
            $markdown,
            'development',
            'statamic://entry::other-456',
        );

        $this->assertNull($result, 'Anchor inside existing anchor text must not produce a new link');
    }

    public function test_markdown_insert_does_not_match_inside_existing_url(): void
    {
        // Statamic internal URLs use `statamic://entry::uuid` form. A keyword
        // matching "statamic" must NOT inject a link into the URL — that
        // produced `[statamic](other-url)://entry::uuid` corruption that
        // breaks the original href entirely.
        $markdown = 'See [Modern web development](statamic://entry::abc-123) for details.';

        $result = BardLinkInserter::insertLinkIntoMarkdown(
            $markdown,
            'statamic',
            'https://example.com/statamic-info',
        );

        $this->assertNull($result, 'Anchor inside existing URL must not produce a new link');
    }

    public function test_markdown_insert_does_not_match_word_inside_external_url(): void
    {
        // Same protection applies to external URLs. A keyword "example"
        // that happens to appear inside `https://example.com/page` must
        // not be wrapped — would produce `[https://[example](url).com/page]`-
        // class corruption.
        $markdown = 'Visit [our docs](https://example.com/page) often.';

        $result = BardLinkInserter::insertLinkIntoMarkdown(
            $markdown,
            'example',
            'statamic://entry::other',
        );

        $this->assertNull($result);
    }

    public function test_markdown_insert_still_finds_safe_occurrence_outside_links(): void
    {
        // Regression guard: when the anchor occurs both INSIDE an existing
        // link and OUTSIDE one, the safe outside occurrence must still get
        // wrapped. Otherwise legitimate matches would silently fail.
        $markdown = 'See [Modern web development](statamic://entry::abc-123) — development is fun.';

        $result = BardLinkInserter::insertLinkIntoMarkdown(
            $markdown,
            'development',
            'statamic://entry::dev-target',
        );

        $this->assertNotNull($result, 'Safe outside occurrence must still be wrapped');
        // Inside-link "development" stays as plain text inside the existing anchor.
        $this->assertStringContainsString('[Modern web development](statamic://entry::abc-123)', $result);
        // Outside-link "development" gets the new link.
        $this->assertStringContainsString('[development](statamic://entry::dev-target)', $result);
    }

    public function test_replicator_walker_skips_meta_keys(): void
    {
        // The set-level keys `type` and `id` are metadata, not content.
        // Even though `id` could match an anchor by coincidence, the
        // walker must skip those keys entirely.
        $sets = [
            [
                'type' => 'coffee',
                'id' => 'coffee-card',
                'heading' => 'irrelevant',
            ],
        ];

        $reflection = new \ReflectionMethod(BardLinkInserter::class, 'processReplicatorWithHref');
        $reflection->setAccessible(true);

        $result = $reflection->invoke(null, $sets, 'coffee', 'statamic://entry::target-123');

        $this->assertNull($result, 'Meta keys must never be linked');
    }
}
