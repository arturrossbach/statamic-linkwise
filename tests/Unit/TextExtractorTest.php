<?php

namespace Inkline\Linkwise\Tests\Unit;

use Inkline\Linkwise\Support\TextExtractor;
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
}
