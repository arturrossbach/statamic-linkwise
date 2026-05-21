<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\TextExtractor;
use PHPUnit\Framework\TestCase;

class TextExtractorTest extends TestCase
{
    public function test_extracts_text_from_paragraphs(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello world'],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Second paragraph'],
                ],
            ],
        ];

        $this->assertSame("Hello world\nSecond paragraph", TextExtractor::fromBard($bard));
    }

    public function test_extracts_text_from_headings(): void
    {
        $bard = [
            [
                'type' => 'heading',
                'attrs' => ['level' => 2],
                'content' => [
                    ['type' => 'text', 'text' => 'Section Title'],
                ],
            ],
        ];

        $this->assertSame('Section Title', TextExtractor::fromBard($bard));
    }

    public function test_extracts_text_with_inline_marks(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'This is '],
                    [
                        'type' => 'text',
                        'text' => 'bold',
                        'marks' => [['type' => 'bold']],
                    ],
                    ['type' => 'text', 'text' => ' text'],
                ],
            ],
        ];

        $this->assertSame('This is bold text', TextExtractor::fromBard($bard));
    }

    public function test_skips_bard_sets(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Before set'],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => 'abc123',
                    'values' => ['type' => 'image', 'image' => 'photo.jpg'],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'After set'],
                ],
            ],
        ];

        $this->assertSame("Before set\nAfter set", TextExtractor::fromBard($bard));
    }

    public function test_extracts_internal_links(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Check out '],
                    [
                        'type' => 'text',
                        'text' => 'this article',
                        'marks' => [
                            [
                                'type' => 'link',
                                'attrs' => ['href' => 'statamic://entry::abc-123-def'],
                            ],
                        ],
                    ],
                    ['type' => 'text', 'text' => ' for more info.'],
                ],
            ],
        ];

        $this->assertSame(['abc-123-def'], TextExtractor::linksFromBard($bard));
    }

    public function test_ignores_external_links(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Visit Google',
                        'marks' => [
                            [
                                'type' => 'link',
                                'attrs' => ['href' => 'https://google.com'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame([], TextExtractor::linksFromBard($bard));
    }

    public function test_deduplicates_links(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'first link',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123']]],
                    ],
                    ['type' => 'text', 'text' => ' and '],
                    [
                        'type' => 'text',
                        'text' => 'second link',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123']]],
                    ],
                ],
            ],
        ];

        $this->assertSame(['abc-123'], TextExtractor::linksFromBard($bard));
    }

    public function test_handles_empty_content(): void
    {
        $this->assertSame('', TextExtractor::fromBard([]));
        $this->assertSame([], TextExtractor::linksFromBard([]));
        $this->assertSame([], TextExtractor::externalLinksFromBard([]));
    }

    public function test_extracts_external_links(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Visit '],
                    [
                        'type' => 'text',
                        'text' => 'Google',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://google.com']],
                        ],
                    ],
                    ['type' => 'text', 'text' => ' and '],
                    [
                        'type' => 'text',
                        'text' => 'Example',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'http://example.com/page']],
                        ],
                    ],
                ],
            ],
        ];

        $links = TextExtractor::externalLinksFromBard($bard);

        $this->assertCount(2, $links);
        $this->assertSame('https://google.com', $links[0]['url']);
        $this->assertSame('Google', $links[0]['anchor_text']);
        $this->assertSame('http://example.com/page', $links[1]['url']);
        $this->assertSame('Example', $links[1]['anchor_text']);
    }

    public function test_merges_adjacent_internal_link_nodes(): void
    {
        // BardLinkInserter creates separate text nodes when a link spans across
        // nodes with different marks (e.g. one word is bold)
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'we all love '],
                    [
                        'type' => 'text',
                        'text' => 'we all love ',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'coffee',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                        ],
                    ],
                    ['type' => 'text', 'text' => ' and tea.'],
                ],
            ],
        ];

        $links = TextExtractor::internalLinksWithAnchorFromBard($bard);

        $this->assertCount(1, $links, 'Adjacent link nodes with same href should be merged');
        $this->assertSame('we all love coffee', $links[0]['anchor_text']);
        $this->assertSame('target-1', $links[0]['entry_id']);
    }

    public function test_merges_when_node_has_dual_link_marks(): void
    {
        // Real-world case: BardLinkInserter added internal link on top of existing external link
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'We all love ',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'coffee',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://www.sueddeutsche.de', 'rel' => null, 'target' => null]],
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                        ],
                    ],
                ],
            ],
        ];

        $links = TextExtractor::internalLinksWithAnchorFromBard($bard);

        $this->assertCount(1, $links, 'Should merge even when second node has dual link marks');
        $this->assertSame('We all love coffee', $links[0]['anchor_text']);
    }

    public function test_does_not_merge_different_link_targets(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'first',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                        ],
                    ],
                    ['type' => 'text', 'text' => ' and '],
                    [
                        'type' => 'text',
                        'text' => 'second',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-2']],
                        ],
                    ],
                ],
            ],
        ];

        $links = TextExtractor::internalLinksWithAnchorFromBard($bard);

        $this->assertCount(2, $links, 'Different link targets should not be merged');
        $this->assertSame('first', $links[0]['anchor_text']);
        $this->assertSame('second', $links[1]['anchor_text']);
    }

    public function test_merges_adjacent_external_link_nodes(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'click ',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com']],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'here',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com']],
                        ],
                    ],
                ],
            ],
        ];

        $links = TextExtractor::externalLinksFromBard($bard);

        $this->assertCount(1, $links);
        $this->assertSame('click here', $links[0]['anchor_text']);
    }

    public function test_external_links_ignores_internal(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'internal',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123']],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame([], TextExtractor::externalLinksFromBard($bard));
    }

    // ─── extractTextAndLinksFromBard (offset-aware walker) ────────────────
    //
    // The lockstep invariant: the new single-pass walker MUST produce the
    // same text as fromBard() and the same link records (sans 'offset') as
    // internalLinksWithAnchorFromBard() / externalLinksFromBard(). The
    // offsets are an additional output. Tests below run on the same Bard
    // fixtures as the legacy walkers to catch any drift.

    public function test_offset_walker_text_matches_legacy_for_paragraphs(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second paragraph']]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);
        $this->assertSame(TextExtractor::fromBard($bard), $out['text']);
    }

    public function test_offset_walker_text_matches_legacy_with_sets_skipped(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before set']]],
            ['type' => 'set', 'attrs' => ['id' => 'x', 'values' => ['type' => 'image', 'image' => 'photo.jpg']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After set']]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);
        $this->assertSame(TextExtractor::fromBard($bard), $out['text']);
    }

    public function test_offset_walker_emits_internal_link_with_correct_offset(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Check out '],
                ['type' => 'text', 'text' => 'this article', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123']],
                ]],
                ['type' => 'text', 'text' => ' for more.'],
            ]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);

        $this->assertSame('Check out this article for more.', $out['text']);
        $this->assertCount(1, $out['internal_links']);
        $this->assertSame('this article', $out['internal_links'][0]['anchor_text']);
        $this->assertSame('abc-123', $out['internal_links'][0]['entry_id']);
        // Offset of "this article" in "Check out this article for more."
        $this->assertSame(10, $out['internal_links'][0]['offset']);
        $this->assertSame('this article', mb_substr($out['text'], 10, mb_strlen('this article')));
    }

    public function test_offset_walker_distinguishes_linked_vs_unlinked_same_anchor(): void
    {
        // THE bug case (2026-05-11): anchor "Erdnuss-Soba-Nudeln" appears
        // unlinked at pos 0, then linked at a later pos. The naive
        // occurrence-counter (extractStructured 4th arg) walked all
        // string-matches and put context at the unlinked position. The
        // offset-aware walker returns the LINKED position directly.
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Ich liebe Erdnuss-Soba-Nudeln mhhhh. Heute über '],
                ['type' => 'text', 'text' => 'Erdnuss-Soba-Nudeln', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                ]],
                ['type' => 'text', 'text' => ' nachgedacht.'],
            ]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);

        $this->assertCount(1, $out['internal_links']);
        $linkedOffset = $out['internal_links'][0]['offset'];
        $unlinkedOffset = mb_stripos($out['text'], 'Erdnuss-Soba-Nudeln');

        $this->assertNotSame($unlinkedOffset, $linkedOffset, 'Offset must point to the LINKED occurrence, not the first string-match.');
        $this->assertSame('Erdnuss-Soba-Nudeln', mb_substr($out['text'], $linkedOffset, 19));
    }

    public function test_offset_walker_correctly_offsets_links_across_paragraphs(): void
    {
        // Two paragraphs, each with one link. Offset must account for the
        // "\n" separator between top-level nodes.
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'A '],
                ['type' => 'text', 'text' => 'first', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::t1']],
                ]],
                ['type' => 'text', 'text' => ' link.'],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'And '],
                ['type' => 'text', 'text' => 'second', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::t2']],
                ]],
                ['type' => 'text', 'text' => '.'],
            ]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);

        $this->assertSame("A first link.\nAnd second.", $out['text']);
        $this->assertCount(2, $out['internal_links']);
        $this->assertSame(2, $out['internal_links'][0]['offset']);
        $this->assertSame('first', mb_substr($out['text'], 2, 5));
        $this->assertSame(18, $out['internal_links'][1]['offset']);
        $this->assertSame('second', mb_substr($out['text'], 18, 6));
    }

    public function test_offset_walker_merges_adjacent_internal_link_nodes(): void
    {
        // Same fixture as test_merges_adjacent_internal_link_nodes for the
        // legacy walker — verify the offset walker produces the same merged
        // anchor.
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'we all love '],
                ['type' => 'text', 'text' => 'we all love ', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                ]],
                ['type' => 'text', 'text' => 'coffee', 'marks' => [
                    ['type' => 'bold'],
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::target-1']],
                ]],
                ['type' => 'text', 'text' => ' and tea.'],
            ]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);

        $this->assertCount(1, $out['internal_links']);
        $this->assertSame('we all love coffee', $out['internal_links'][0]['anchor_text']);
        $this->assertSame('target-1', $out['internal_links'][0]['entry_id']);
        $offset = $out['internal_links'][0]['offset'];
        $this->assertSame('we all love coffee', mb_substr($out['text'], $offset, 18));
    }

    public function test_offset_walker_does_not_merge_different_targets(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'first', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::t1']],
                ]],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'second', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::t2']],
                ]],
            ]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);

        $this->assertCount(2, $out['internal_links']);
        $this->assertSame('first', $out['internal_links'][0]['anchor_text']);
        $this->assertSame('second', $out['internal_links'][1]['anchor_text']);
    }

    public function test_offset_walker_external_links_with_offset(): void
    {
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Visit '],
                ['type' => 'text', 'text' => 'Google', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://google.com']],
                ]],
                ['type' => 'text', 'text' => ' or '],
                ['type' => 'text', 'text' => 'Example', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'http://example.com/p']],
                ]],
            ]],
        ];
        $out = TextExtractor::extractTextAndLinksFromBard($bard);

        $this->assertCount(2, $out['external_links']);
        $this->assertSame('https://google.com', $out['external_links'][0]['url']);
        $this->assertSame(6, $out['external_links'][0]['offset']);
        $this->assertSame('Google', mb_substr($out['text'], 6, 6));
        $this->assertSame('http://example.com/p', $out['external_links'][1]['url']);
        $this->assertSame('Example', mb_substr($out['text'], $out['external_links'][1]['offset'], 7));
    }

    public function test_markdown_walker_strips_link_syntax_and_offsets_links(): void
    {
        $md = 'Read [the docs](statamic://entry::doc-1) and [Google](https://google.com) for more.';
        $out = TextExtractor::extractTextAndLinksFromMarkdown($md);

        $this->assertSame('Read the docs and Google for more.', $out['text']);
        $this->assertCount(1, $out['internal_links']);
        $this->assertSame('doc-1', $out['internal_links'][0]['entry_id']);
        $this->assertSame(5, $out['internal_links'][0]['offset']);
        $this->assertSame('the docs', mb_substr($out['text'], 5, 8));
        $this->assertCount(1, $out['external_links']);
        $this->assertSame('https://google.com', $out['external_links'][0]['url']);
        $this->assertSame('Google', mb_substr($out['text'], $out['external_links'][0]['offset'], 6));
    }

    public function test_markdown_walker_strips_formatting_chars(): void
    {
        $md = "# Title\n\nSome **bold** and *italic* text.";
        $out = TextExtractor::extractTextAndLinksFromMarkdown($md);
        // # * _ ~ ` > all stripped; newlines are preserved (paragraph breaks
        // matter for ContextExtractor's paragraph-boundary clamp).
        $this->assertSame(" Title\n\nSome bold and italic text.", $out['text']);
    }

    public function test_markdown_walker_distinguishes_linked_vs_unlinked_same_anchor(): void
    {
        // The bug case again, in Markdown form: same anchor appears unlinked
        // first, then linked. Offset must point to the LINKED position.
        $md = 'I love coffee. Recommended: [coffee](statamic://entry::t1) every morning.';
        $out = TextExtractor::extractTextAndLinksFromMarkdown($md);

        $unlinkedPos = mb_stripos($out['text'], 'coffee');
        $linkedPos = $out['internal_links'][0]['offset'];
        $this->assertNotSame($unlinkedPos, $linkedPos);
        $this->assertSame('coffee', mb_substr($out['text'], $linkedPos, 6));
    }

    public function test_markdown_walker_no_links_emits_only_text(): void
    {
        $out = TextExtractor::extractTextAndLinksFromMarkdown('Just plain text.');
        $this->assertSame('Just plain text.', $out['text']);
        $this->assertSame([], $out['internal_links']);
        $this->assertSame([], $out['external_links']);
    }

    public function test_offset_walker_text_matches_fromBard_for_all_legacy_fixtures(): void
    {
        // Lockstep guard: every Bard fixture in this file's other tests must
        // produce identical text via the offset walker. If the legacy
        // fromBard's behavior ever shifts and we forget to update the new
        // walker, this test catches it.
        $fixtures = [
            // Paragraph + heading + sets + inline marks combo
            [
                ['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'Title']]],
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Plain '],
                    ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' end.'],
                ]],
                ['type' => 'set', 'attrs' => ['id' => 'x', 'values' => ['type' => 'image', 'image' => 'p.jpg']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After']]],
            ],
            // Code blocks skipped
            [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prose']]],
                ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'import x']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'More prose']]],
            ],
            // Bullet list with block separator
            [
                ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']]],
                    ]],
                    ['type' => 'listItem', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']]],
                    ]],
                ]],
            ],
        ];
        foreach ($fixtures as $i => $bard) {
            $this->assertSame(
                TextExtractor::fromBard($bard),
                TextExtractor::extractTextAndLinksFromBard($bard)['text'],
                "Fixture #$i — text drift between fromBard and extractTextAndLinksFromBard"
            );
        }
    }
}
