<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BardWalker;
use PHPUnit\Framework\TestCase;

/**
 * BardWalker is the single source of truth for "how to traverse a Bard
 * tree" — the same set of nodes that link-detection walks across
 * InboundEngine, AutoLinkApplier, and UrlReplacer reach. Tests cover
 * the core invariants:
 *
 *   - visits the outer-array nodes
 *   - recurses into $node['content']
 *   - recurses into Bard 'set' attrs.values (the historical blind-spot)
 *   - early-stop via visitor returning true
 *   - skips non-array nodes silently (mid-edit data resilience)
 *   - skips Bard set's metadata keys (type/id/enabled) and string values
 */
class BardWalkerTest extends TestCase
{
    public function test_walks_top_level_nodes(): void
    {
        $tree = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'World']]],
        ];

        $visited = [];
        BardWalker::walk($tree, function ($node) use (&$visited) {
            if (($node['type'] ?? '') === 'text') {
                $visited[] = $node['text'];
            }
        });

        $this->assertSame(['Hello', 'World'], $visited);
    }

    public function test_recurses_into_node_content(): void
    {
        // Verify nested paragraphs / list items / table cells are reached.
        $tree = [[
            'type' => 'bulletList',
            'content' => [[
                'type' => 'listItem',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Deeply nested']],
                ]],
            ]],
        ]];

        $found = null;
        BardWalker::walk($tree, function ($node) use (&$found) {
            if (($node['type'] ?? '') === 'text') {
                $found = $node['text'];
            }
        });

        $this->assertSame('Deeply nested', $found);
    }

    public function test_recurses_into_bard_set_attrs_values(): void
    {
        // The historical blind-spot: Bard 'set' nodes carry their fields
        // under attrs.values, not content. The walker must reach a text
        // node embedded inside a set's nested Bard fragment — without
        // that, link-detection inside Peak Cards / pull-quotes / button
        // labels was invisible across 3 modules until 2026-05-09.
        $tree = [[
            'type' => 'set',
            'attrs' => [
                'values' => [
                    'type' => 'pull_quote',
                    'enabled' => true,
                    'id' => 'abc',
                    'body' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Quote text']],
                    ]],
                ],
            ],
        ]];

        $found = null;
        BardWalker::walk($tree, function ($node) use (&$found) {
            if (($node['type'] ?? '') === 'text') {
                $found = $node['text'];
            }
        });

        $this->assertSame('Quote text', $found);
    }

    public function test_skips_set_metadata_keys(): void
    {
        // attrs.values has type/id/enabled siblings that aren't Bard
        // content. Walker must not try to recurse into them.
        $tree = [[
            'type' => 'set',
            'attrs' => [
                'values' => [
                    'type' => 'pull_quote',
                    'enabled' => true,
                    'id' => 'abc-xyz',
                    'body' => [[
                        'type' => 'text',
                        'text' => 'Body',
                    ]],
                ],
            ],
        ]];

        $visitedTypes = [];
        BardWalker::walk($tree, function ($node) use (&$visitedTypes) {
            $visitedTypes[] = $node['type'] ?? null;
        });

        // The 'set' node itself + the 'text' from values.body. NOT
        // 'pull_quote' (string in metadata position) or anything from
        // type/id/enabled.
        $this->assertSame(['set', 'text'], $visitedTypes);
    }

    public function test_skips_string_values_in_set(): void
    {
        // String fields inside a set (button label, heading) aren't
        // Bard trees — recursiveChildren must not yield them, so walk
        // doesn't visit them as nodes (would treat the string as a
        // node-array and crash).
        $tree = [[
            'type' => 'set',
            'attrs' => [
                'values' => [
                    'type' => 'button',
                    'enabled' => true,
                    'id' => 'btn',
                    'label' => 'Click me',  // string, not Bard
                    'href' => '/foo',       // string, not Bard
                ],
            ],
        ]];

        $count = 0;
        BardWalker::walk($tree, function () use (&$count) {
            $count++;
        });

        // Only the outer 'set' node visited. String children skipped.
        $this->assertSame(1, $count);
    }

    public function test_early_stops_when_visitor_returns_true(): void
    {
        // "Has any node match X" pattern: visitor returns true on first
        // match, walker bails immediately without visiting the rest.
        $tree = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'target']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'never']]],
        ];

        $visited = [];
        $stopped = BardWalker::walk($tree, function ($node) use (&$visited) {
            if (($node['type'] ?? '') === 'text') {
                $visited[] = $node['text'];
                if ($node['text'] === 'target') {
                    return true; // early-stop
                }
            }
            return false;
        });

        $this->assertTrue($stopped);
        $this->assertSame(['first', 'target'], $visited);
    }

    public function test_returns_false_when_visitor_never_stops(): void
    {
        $tree = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'foo']]]];

        $stopped = BardWalker::walk($tree, fn () => false);

        $this->assertFalse($stopped);
    }

    public function test_silently_skips_non_array_nodes(): void
    {
        // Mid-edit Bard data can have null / scalar entries in places
        // walker would try to recurse into. Walker must not crash.
        $tree = [
            null,
            'not-an-array',
            42,
            ['type' => 'paragraph', 'content' => [
                null,
                ['type' => 'text', 'text' => 'survived'],
            ]],
        ];

        $found = null;
        BardWalker::walk($tree, function ($node) use (&$found) {
            if (($node['type'] ?? '') === 'text') {
                $found = $node['text'];
            }
        });

        $this->assertSame('survived', $found);
    }

    public function test_recursive_children_yields_content_array(): void
    {
        $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']]];

        $yielded = iterator_to_array(BardWalker::recursiveChildren($node));

        $this->assertCount(1, $yielded);
        $this->assertSame([['type' => 'text', 'text' => 'A']], $yielded[0]);
    }

    public function test_recursive_children_yields_set_attrs_values_bard_fragments(): void
    {
        $node = [
            'type' => 'set',
            'attrs' => [
                'values' => [
                    'type' => 'pull_quote',
                    'id' => 'x',
                    'enabled' => true,
                    'body' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'B']]]],
                    'caption' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'C']]]],
                    'label' => 'string-not-yielded',
                ],
            ],
        ];

        $yielded = iterator_to_array(BardWalker::recursiveChildren($node), false);

        // body + caption (each a Bard fragment) → 2 yields. label (string)
        // and metadata (type/id/enabled) → 0 yields.
        $this->assertCount(2, $yielded);
    }

    public function test_recursive_children_yields_nothing_for_plain_text_node(): void
    {
        $node = ['type' => 'text', 'text' => 'foo'];

        $yielded = iterator_to_array(BardWalker::recursiveChildren($node));

        $this->assertSame([], $yielded);
    }

    // ─── normalizeChildren ─────────────────────────────────────────────────
    //
    // The invariant: a Bard children-array never contains two adjacent text
    // nodes with the same mark-set. Tests below cover the cases that produce
    // such fragments in production (Re-Link flow, unlink-then-insert across
    // splits) and the cases that must NOT collapse (different marks, non-text
    // separators, codeBlock content).

    public function test_normalize_merges_adjacent_plain_text_nodes(): void
    {
        $children = [
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'text', 'text' => 'world'],
        ];

        $out = BardWalker::normalizeChildren($children);

        $this->assertCount(1, $out);
        $this->assertSame('Hello world', $out[0]['text']);
    }

    public function test_normalize_merges_adjacent_same_href_link_marks(): void
    {
        // The Bug 16 case: anchor "über Erdnuss-Soba-Nudeln" stored as two
        // adjacent text nodes both linking to the same target. Display
        // merges them, write path didn't — this normalisation closes the gap.
        $href = 'statamic://entry::target123';
        $children = [
            ['type' => 'text', 'text' => 'Heute '],
            ['type' => 'text', 'text' => 'über ', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'Erdnuss-Soba-Nudeln', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => ' nachgedacht.'],
        ];

        $out = BardWalker::normalizeChildren($children);

        $this->assertCount(3, $out);
        $this->assertSame('Heute ', $out[0]['text']);
        $this->assertSame('über Erdnuss-Soba-Nudeln', $out[1]['text']);
        $this->assertSame($href, $out[1]['marks'][0]['attrs']['href']);
        $this->assertSame(' nachgedacht.', $out[2]['text']);
    }

    public function test_normalize_does_not_merge_different_hrefs(): void
    {
        $children = [
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://y.test']]]],
        ];

        $out = BardWalker::normalizeChildren($children);

        // Different hrefs are semantically different links — must stay split.
        $this->assertCount(2, $out);
    }

    public function test_normalize_does_not_merge_text_split_by_non_text_node(): void
    {
        $children = [
            ['type' => 'text', 'text' => 'before'],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'after'],
        ];

        $out = BardWalker::normalizeChildren($children);

        // hardBreak (or any non-text node) breaks adjacency. Merging across
        // it would silently change rendering.
        $this->assertCount(3, $out);
        $this->assertSame('before', $out[0]['text']);
        $this->assertSame('hardBreak', $out[1]['type']);
        $this->assertSame('after', $out[2]['text']);
    }

    public function test_normalize_merges_three_or_more_adjacent_same_mark(): void
    {
        // Walker handles runs of N, not just pairs.
        $children = [
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'c', 'marks' => [['type' => 'bold']]],
        ];

        $out = BardWalker::normalizeChildren($children);

        $this->assertCount(1, $out);
        $this->assertSame('abc', $out[0]['text']);
    }

    public function test_normalize_merges_marks_in_different_order(): void
    {
        // Bold+link and link+bold are semantically identical — must merge.
        // Different code paths emit marks in different orders; the merge
        // must not depend on array order to catch real fragments.
        $href = 'https://x.test';
        $children = [
            ['type' => 'text', 'text' => 'A', 'marks' => [
                ['type' => 'bold'],
                ['type' => 'link', 'attrs' => ['href' => $href]],
            ]],
            ['type' => 'text', 'text' => 'B', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => $href]],
                ['type' => 'bold'],
            ]],
        ];

        $out = BardWalker::normalizeChildren($children);

        $this->assertCount(1, $out);
        $this->assertSame('AB', $out[0]['text']);
    }

    public function test_normalize_recurses_into_nested_content(): void
    {
        // Real Bard trees nest: blockquote → paragraph → text. Invariant
        // must hold all the way down, not only at the top level.
        $children = [
            [
                'type' => 'blockquote',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'hel'],
                            ['type' => 'text', 'text' => 'lo'],
                        ],
                    ],
                ],
            ],
        ];

        $out = BardWalker::normalizeChildren($children);

        $this->assertCount(1, $out[0]['content'][0]['content']);
        $this->assertSame('hello', $out[0]['content'][0]['content'][0]['text']);
    }

    public function test_normalize_recurses_into_bard_set_attrs_values(): void
    {
        // Bard 'set' nodes carry nested Bard fragments under attrs.values
        // (Peak Cards, pull-quotes, …). Same invariant must hold inside.
        $children = [
            [
                'type' => 'set',
                'attrs' => [
                    'values' => [
                        'type' => 'peak_card',
                        'id' => 'abc',
                        'body' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => 'foo'],
                                    ['type' => 'text', 'text' => 'bar'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $out = BardWalker::normalizeChildren($children);

        $body = $out[0]['attrs']['values']['body'];
        $this->assertCount(1, $body[0]['content']);
        $this->assertSame('foobar', $body[0]['content'][0]['text']);
    }

    public function test_normalize_leaves_codeblock_content_untouched(): void
    {
        // codeBlock content is opaque to Linkwise — same contract as the
        // rest of BardWalker / BardLinkInserter. Even if a code block had
        // adjacent text children (uncommon but possible), we don't touch.
        $children = [
            [
                'type' => 'codeBlock',
                'content' => [
                    ['type' => 'text', 'text' => 'int '],
                    ['type' => 'text', 'text' => 'x;'],
                ],
            ],
        ];

        $out = BardWalker::normalizeChildren($children);

        // Both children survive — code content is preserved verbatim.
        $this->assertCount(2, $out[0]['content']);
        $this->assertSame('int ', $out[0]['content'][0]['text']);
        $this->assertSame('x;', $out[0]['content'][1]['text']);
    }

    public function test_normalize_empty_input_returns_empty(): void
    {
        $this->assertSame([], BardWalker::normalizeChildren([]));
    }

    public function test_normalize_single_child_returns_unchanged(): void
    {
        // Trivial single-child case: no adjacency to merge, structure passes through.
        $children = [['type' => 'text', 'text' => 'lonely']];
        $this->assertSame($children, BardWalker::normalizeChildren($children));
    }

    public function test_normalize_pure_does_not_mutate_input(): void
    {
        // The helper is documented as pure. Verify: input array stays
        // untouched after the call (no by-reference surprises).
        $href = 'https://x.test';
        $children = [
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ];
        $snapshot = $children;

        BardWalker::normalizeChildren($children);

        $this->assertEquals($snapshot, $children);
    }
}
