<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests that verify URL replacement produces correct content.
 * Tests the actual Bard/Markdown/Replicator content transformation,
 * not just return values.
 */
class UrlReplacerIntegrationTest extends TestCase
{
    protected UrlReplacer $replacer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replacer = new UrlReplacer;
    }

    // ─── Bard: Verify content after replacement ────────────────────────────────

    public function test_bard_replace_changes_only_target_href(): void
    {
        $bard = $this->buildBardWithLinks([
            ['text' => 'First link', 'href' => 'https://old.com/page-1'],
            ['text' => 'Second link', 'href' => 'https://old.com/page-2'],
            ['text' => 'Keep this', 'href' => 'https://keep.com'],
        ]);

        // Replace only idx:1 (page-2)
        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com/page-2', 'https://new.com/page-2', 1);

        $this->assertTrue($replaced);

        // Verify each link individually
        $links = $this->extractLinksFromBard($result);
        $this->assertCount(3, $links);
        $this->assertSame('https://old.com/page-1', $links[0]['href']); // untouched
        $this->assertSame('First link', $links[0]['text']);
        $this->assertSame('https://new.com/page-2', $links[1]['href']); // changed
        $this->assertSame('Second link', $links[1]['text']); // anchor preserved
        $this->assertSame('https://keep.com', $links[2]['href']); // untouched
    }

    public function test_bard_replace_preserves_surrounding_text(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Before '],
                    ['type' => 'text', 'text' => 'linked', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
                    ['type' => 'text', 'text' => ' after.'],
                ],
            ],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', 'https://new.com', 0);

        $this->assertTrue($replaced);

        // Surrounding text must be untouched
        $nodes = $result[0]['content'];
        $this->assertSame('Before ', $nodes[0]['text']);
        $this->assertSame('linked', $nodes[1]['text']);
        $this->assertSame('https://new.com', $nodes[1]['marks'][0]['attrs']['href']);
        $this->assertSame(' after.', $nodes[2]['text']);
    }

    public function test_bard_replace_preserves_other_marks(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Bold italic link',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'italic'],
                            ['type' => 'link', 'attrs' => ['href' => 'https://old.com']],
                        ],
                    ],
                ],
            ],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', 'https://new.com', 0);

        $marks = $result[0]['content'][0]['marks'];
        $this->assertCount(3, $marks);
        $this->assertSame('bold', $marks[0]['type']);
        $this->assertSame('italic', $marks[1]['type']);
        $this->assertSame('link', $marks[2]['type']);
        $this->assertSame('https://new.com', $marks[2]['attrs']['href']);
    }

    public function test_bard_replace_preserves_link_attributes(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Link',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://old.com', 'target' => '_blank', 'rel' => 'nofollow']],
                        ],
                    ],
                ],
            ],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', 'https://new.com', 0);

        $attrs = $result[0]['content'][0]['marks'][0]['attrs'];
        $this->assertSame('https://new.com', $attrs['href']);
        $this->assertSame('_blank', $attrs['target']); // preserved
        $this->assertSame('nofollow', $attrs['rel']); // preserved
    }

    public function test_bard_nth_replace_correct_position_in_mixed_content(): void
    {
        // Simulate real content: multiple paragraphs, some with links, some without
        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'No links in this paragraph.'],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Visit '],
                ['type' => 'text', 'text' => 'page one', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/one']]]],
                ['type' => 'text', 'text' => ' for details.'],
            ]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [
                ['type' => 'text', 'text' => 'Section Two'],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Also see '],
                ['type' => 'text', 'text' => 'page two', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/two']]]],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'page three', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/three']]]],
                ['type' => 'text', 'text' => '.'],
            ]],
        ];

        // Replace idx:2 which should be "page three" (0=one, 1=two, 2=three)
        [$result, $replaced] = $this->replacer->replaceNthInBard(
            $bard, 'example.com', 'https://example.com/three', 'https://newsite.com/three', 2
        );

        $this->assertTrue($replaced);

        $links = $this->extractLinksFromBard($result);
        $this->assertSame('https://example.com/one', $links[0]['href']); // untouched
        $this->assertSame('https://example.com/two', $links[1]['href']); // untouched
        $this->assertSame('https://newsite.com/three', $links[2]['href']); // replaced
        $this->assertSame('page three', $links[2]['text']); // anchor preserved
    }

    // ─── Markdown: Verify content after replacement ────────────────────────────

    public function test_markdown_replace_changes_only_target_url(): void
    {
        $md = "Read [intro](https://old.com/intro) and [guide](https://old.com/guide) for more. Also see [other](https://other.com).";

        // Replace only idx:1 (guide). $search === $oldUrl (exact-mode style).
        [$result, $replaced] = $this->replacer->replaceNthInMarkdown($md, 'https://old.com/guide', 'https://old.com/guide', 'https://new.com/guide', 0);

        $this->assertTrue($replaced);
        $this->assertStringContainsString('[intro](https://old.com/intro)', $result); // untouched
        $this->assertStringContainsString('[guide](https://new.com/guide)', $result); // changed
        $this->assertStringContainsString('[other](https://other.com)', $result); // untouched
        // Anchor text preserved
        $this->assertStringContainsString('[guide]', $result);
    }

    public function test_markdown_replace_preserves_surrounding_content(): void
    {
        $md = "Before the link [click here](https://old.com) and after the link.\n\nAnother paragraph.";

        [$result, $replaced] = $this->replacer->replaceNthInMarkdown($md, 'https://old.com', 'https://old.com', 'https://new.com', 0);

        $this->assertTrue($replaced);
        $this->assertStringStartsWith('Before the link', $result);
        $this->assertStringContainsString('and after the link.', $result);
        $this->assertStringContainsString('Another paragraph.', $result);
    }

    // ─── Replicator: Verify content after replacement ──────────────────────────

    public function test_replicator_replace_targets_correct_nested_link(): void
    {
        $sets = [
            [
                'type' => 'intro',
                'id' => 'set-1',
                'enabled' => true,
                'content' => $this->buildBardWithLinks([
                    ['text' => 'Intro link', 'href' => 'https://example.com/intro'],
                ]),
            ],
            [
                'type' => 'body',
                'id' => 'set-2',
                'enabled' => true,
                'article' => $this->buildBardWithLinks([
                    ['text' => 'Body link one', 'href' => 'https://example.com/one'],
                    ['text' => 'Body link two', 'href' => 'https://example.com/two'],
                ]),
            ],
        ];

        // idx:0=intro, idx:1=one, idx:2=two — replace idx:2
        [$result, $replaced] = $this->replacer->replaceNthInReplicator(
            $sets, 'example.com', 'https://example.com/two', 'https://newsite.com/two', 2
        );

        $this->assertTrue($replaced);

        // Verify intro link untouched
        $introLinks = $this->extractLinksFromBard($result[0]['content']);
        $this->assertSame('https://example.com/intro', $introLinks[0]['href']);

        // Verify body links
        $bodyLinks = $this->extractLinksFromBard($result[1]['article']);
        $this->assertSame('https://example.com/one', $bodyLinks[0]['href']); // untouched
        $this->assertSame('https://newsite.com/two', $bodyLinks[1]['href']); // replaced
        $this->assertSame('Body link two', $bodyLinks[1]['text']); // anchor preserved
    }

    // ─── Domain matching: Replace with domain swap ─────────────────────────────

    public function test_domain_replace_preserves_path(): void
    {
        $bard = $this->buildBardWithLinks([
            ['text' => 'Deep page', 'href' => 'https://old-site.com/blog/2024/my-article?ref=twitter#comments'],
        ]);

        $result = $this->replacer->replaceInBard($bard, 'old-site.com', 'new-site.com');

        $links = $this->extractLinksFromBard($result);
        $this->assertSame('https://new-site.com/blog/2024/my-article?ref=twitter#comments', $links[0]['href']);
    }

    // ─── Preview and apply consistency ──────────────────────────────────────────

    public function test_preview_and_nth_replace_use_same_indices(): void
    {
        $bard = $this->buildBardWithLinks([
            ['text' => 'A', 'href' => 'https://target.com/a'],
            ['text' => 'B', 'href' => 'https://other.com/b'],
            ['text' => 'C', 'href' => 'https://target.com/c'],
            ['text' => 'D', 'href' => 'https://target.com/d'],
        ]);

        // Preview finds 3 target.com links
        $found = $this->replacer->findInBard($bard, 'target.com');
        $this->assertCount(3, $found);
        $this->assertSame(0, $found[0]['occurrence_index']);
        $this->assertSame('A', $found[0]['anchor_text']);
        $this->assertSame(1, $found[1]['occurrence_index']);
        $this->assertSame('C', $found[1]['anchor_text']);
        $this->assertSame(2, $found[2]['occurrence_index']);
        $this->assertSame('D', $found[2]['anchor_text']);

        // Replace idx:1 (should be "C", not "B")
        [$result, $replaced] = $this->replacer->replaceNthInBard(
            $bard, 'target.com', 'https://target.com/c', 'https://replaced.com', 1
        );

        $this->assertTrue($replaced);

        $links = $this->extractLinksFromBard($result);
        $this->assertSame('https://target.com/a', $links[0]['href']); // idx:0 untouched
        $this->assertSame('https://other.com/b', $links[1]['href']); // not in target.com, untouched
        $this->assertSame('https://replaced.com', $links[2]['href']); // idx:1 replaced
        $this->assertSame('C', $links[2]['text']); // correct anchor
        $this->assertSame('https://target.com/d', $links[3]['href']); // idx:2 untouched
    }

    // ─── Unlink (remove link mark, keep text) ─────────────────────────────────

    public function test_unlink_removes_mark_keeps_text(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Before '],
                    ['type' => 'text', 'text' => 'linked text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://old.com']]]],
                    ['type' => 'text', 'text' => ' after.'],
                ],
            ],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', \Arturrossbach\Linkwise\Support\UrlHelper::UNLINK, 0);

        $this->assertTrue($replaced);

        // Text node should still exist but without marks
        $node = $result[0]['content'][1];
        $this->assertSame('linked text', $node['text']);
        $this->assertArrayNotHasKey('marks', $node);
    }

    public function test_unlink_preserves_other_marks(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'bold linked',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'link', 'attrs' => ['href' => 'https://old.com']],
                        ],
                    ],
                ],
            ],
        ];

        [$result, $replaced] = $this->replacer->replaceNthInBard($bard, 'old.com', 'https://old.com', \Arturrossbach\Linkwise\Support\UrlHelper::UNLINK, 0);

        $this->assertTrue($replaced);
        $marks = $result[0]['content'][0]['marks'];
        $this->assertCount(1, $marks);
        $this->assertSame('bold', $marks[0]['type']);
    }

    public function test_unlink_in_markdown(): void
    {
        $md = 'See [click here](https://old.com) for more.';

        [$result, $replaced] = $this->replacer->replaceNthInMarkdown($md, 'https://old.com', 'https://old.com', \Arturrossbach\Linkwise\Support\UrlHelper::UNLINK, 0);

        $this->assertTrue($replaced);
        $this->assertSame('See click here for more.', $result);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build Bard content with links, one paragraph per link.
     */
    protected function buildBardWithLinks(array $links): array
    {
        $bard = [];
        foreach ($links as $link) {
            $bard[] = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $link['text'],
                        'marks' => [['type' => 'link', 'attrs' => ['href' => $link['href']]]],
                    ],
                ],
            ];
        }

        return $bard;
    }

    /**
     * Extract all links from Bard content in traversal order.
     *
     * @return array<array{text: string, href: string}>
     */
    protected function extractLinksFromBard(array $bard): array
    {
        $links = [];
        $this->collectLinks($bard, $links);

        return $links;
    }

    protected function collectLinks(array $nodes, array &$links): void
    {
        foreach ($nodes as $node) {
            if (isset($node['marks'])) {
                foreach ($node['marks'] as $mark) {
                    if (($mark['type'] ?? '') === 'link') {
                        $links[] = [
                            'text' => $node['text'] ?? '',
                            'href' => $mark['attrs']['href'] ?? '',
                        ];
                    }
                }
            }
            if (isset($node['content'])) {
                $this->collectLinks($node['content'], $links);
            }
        }
    }
}
