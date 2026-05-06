<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\EntryFieldWalker;
use PHPUnit\Framework\TestCase;

/**
 * Read/write symmetry guards for EntryFieldWalker.
 *
 * Background: pre-1.0 the walker treated plain-string values nested in
 * Replicator sets as markdown, so any `[text](url)` syntax there was
 * surfaced as a link. Combined with BardLinkInserter (also pre-retreat)
 * that wrote markdown into those plain-string fields, the system worked —
 * read what we wrote.
 *
 * After the retreat (BardLinkInserter no longer writes markdown into
 * plain-string fields, since Statamic renders `text`/`textarea` as plain
 * text), the walker still reading those fields became a coverage gap:
 * outbound links would be REPORTED in DetailModal but never REMOVABLE,
 * because UrlReplacer also skips plain-strings. These tests pin the new
 * behaviour: walkReplicator IGNORES plain-string values and only visits
 * Bard fragments and nested Replicator sets.
 */
class EntryFieldWalkerTest extends TestCase
{
    public function test_walk_replicator_skips_plain_string_values(): void
    {
        // A typical Peak Cards set: plain-string heading + Bard text field.
        // Pre-fix, the walker invoked $onMarkdown on the heading string and
        // any `[text](url)` syntax there would surface as a "link".
        $sets = [
            [
                'type' => 'card',
                'id' => 'set-1',
                'heading' => 'Some Heading! [Best Practices](statamic://entry::abc-123)!',
                'text' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Body content here.'],
                        ],
                    ],
                ],
            ],
        ];

        $bardCalls = [];
        $markdownCalls = [];

        EntryFieldWalker::walkReplicator(
            $sets,
            function (array $bard) use (&$bardCalls) {
                $bardCalls[] = $bard;
            },
            function (string $md) use (&$markdownCalls) {
                $markdownCalls[] = $md;
            },
        );

        $this->assertCount(1, $bardCalls, 'Bard fragment should be visited exactly once');
        $this->assertSame([], $markdownCalls, 'No plain-string should be passed to $onMarkdown');
    }

    public function test_walk_replicator_recurses_into_nested_replicator(): void
    {
        // Nested replicator (e.g. Grid containing Cards): the walker should
        // still descend into nested set arrays so Bard fragments inside
        // them get visited.
        $sets = [
            [
                'type' => 'grid',
                'id' => 'set-outer',
                'cells' => [
                    [
                        'type' => 'card',
                        'id' => 'set-inner',
                        'heading' => 'Plain string with [Best Practices](statamic://entry::abc) inside',
                        'body' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => 'Inner body.'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $bardCalls = [];
        $markdownCalls = [];

        EntryFieldWalker::walkReplicator(
            $sets,
            function (array $bard) use (&$bardCalls) {
                $bardCalls[] = $bard;
            },
            function (string $md) use (&$markdownCalls) {
                $markdownCalls[] = $md;
            },
        );

        $this->assertCount(1, $bardCalls, 'Nested Bard fragment should be visited');
        $this->assertSame([], $markdownCalls, 'Nested plain-string heading must not be passed to $onMarkdown');
    }

    public function test_walk_replicator_visits_bard_with_link_marks(): void
    {
        // Real ProseMirror link marks (the only "links" that exist in Bard
        // by design) must still be reachable. The walker hands over the
        // full fragment; downstream extractors look at marks, not text.
        $sets = [
            [
                'type' => 'card',
                'id' => 'set-1',
                'body' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Best Practices',
                                'marks' => [
                                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $bardCalls = [];

        EntryFieldWalker::walkReplicator($sets, function (array $bard) use (&$bardCalls) {
            $bardCalls[] = $bard;
        });

        $this->assertCount(1, $bardCalls);
        $hasLink = false;
        foreach ($bardCalls[0][0]['content'] as $node) {
            foreach ($node['marks'] ?? [] as $mark) {
                if (($mark['type'] ?? '') === 'link') {
                    $hasLink = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($hasLink, 'Bard link mark must reach the $onBard callback');
    }
}
