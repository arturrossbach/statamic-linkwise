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
}
