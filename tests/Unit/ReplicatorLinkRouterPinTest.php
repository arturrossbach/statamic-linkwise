<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\Replicator\ReplicatorLinkRouter;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation pin-tests for the Replicator inserter family —
 * written BEFORE the REV-OB-03 Phase B extraction of these methods
 * into src/Support/Replicator/ReplicatorLinkRouter.php, so the move
 * has a behavioural net.
 *
 * Scope (the five methods scheduled to leave BardLinkInserter):
 *   - insertLinkAtPositionInReplicator   (RelinkService Step C path — Bug 17–20 class)
 *   - processReplicatorWithHref          (single-insert with context guard)
 *   - canInsertLinkIntoReplicator        (dry-run mirror)
 *   - processAllInReplicator             (multi-insert; protected)
 *   - countLinksToInReplicator           (protected helper)
 *
 * The position-API path is the one with zero direct coverage today —
 * see memory:bug_18_19_architecture_debt. These tests pin the public
 * contract so the relocation cannot silently regress
 * navigation, validation, and failure propagation.
 *
 * @see \Arturrossbach\Linkwise\Support\BardLinkInserter::insertLinkAtPositionInReplicator
 */
class ReplicatorLinkRouterPinTest extends TestCase
{
    private const HREF = 'statamic://entry::target-123';

    // ─── insertLinkAtPositionInReplicator ───────────────────────────────

    public function test_position_happy_path_single_level_replicator(): void
    {
        $sets = [
            [
                'type' => 'text_block',
                'id' => 'set-1',
                'body' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Follow the Redis Setup Guide today.'],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLinkAtPositionInReplicator(
            $sets,
            'Redis Setup Guide',
            self::HREF,
            replicatorPath: [['set_index' => 0, 'key' => 'body']],
            paragraphPath: [0],
            charStart: 11,
            charEnd: 28,
        );

        $this->assertTrue($result['ok'], json_encode($result));
        $linked = $result['content'][0]['body'][0]['content'][1];
        $this->assertSame('Redis Setup Guide', $linked['text']);
        $this->assertSame('link', $linked['marks'][0]['type']);
        $this->assertSame(self::HREF, $linked['marks'][0]['attrs']['href']);
    }

    public function test_position_invalid_when_replicator_path_empty(): void
    {
        $result = BardLinkInserter::insertLinkAtPositionInReplicator(
            [['type' => 't', 'id' => 's', 'body' => []]],
            'X',
            self::HREF,
            replicatorPath: [],
            paragraphPath: [0],
            charStart: 0,
            charEnd: 1,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_position', $result['reason']);
    }

    public function test_position_invalid_when_set_index_missing(): void
    {
        $sets = [['type' => 't', 'id' => 's', 'body' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'foo']]],
        ]]];

        $result = BardLinkInserter::insertLinkAtPositionInReplicator(
            $sets,
            'foo',
            self::HREF,
            replicatorPath: [['set_index' => 99, 'key' => 'body']],
            paragraphPath: [0],
            charStart: 0,
            charEnd: 3,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_position', $result['reason']);
    }

    public function test_position_invalid_when_key_missing(): void
    {
        $sets = [['type' => 't', 'id' => 's', 'body' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'foo']]],
        ]]];

        $result = BardLinkInserter::insertLinkAtPositionInReplicator(
            $sets,
            'foo',
            self::HREF,
            replicatorPath: [['set_index' => 0, 'key' => 'no_such_key']],
            paragraphPath: [0],
            charStart: 0,
            charEnd: 3,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_position', $result['reason']);
    }

    public function test_position_nested_replicator_two_levels(): void
    {
        $sets = [
            [
                'type' => 'outer',
                'id' => 'o-1',
                'inner' => [
                    [
                        'type' => 'text_block',
                        'id' => 'i-1',
                        'body' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'foo bar baz']]],
                        ],
                    ],
                ],
            ],
        ];

        $result = BardLinkInserter::insertLinkAtPositionInReplicator(
            $sets,
            'bar',
            self::HREF,
            replicatorPath: [
                ['set_index' => 0, 'key' => 'inner'],
                ['set_index' => 0, 'key' => 'body'],
            ],
            paragraphPath: [0],
            charStart: 4,
            charEnd: 7,
        );

        $this->assertTrue($result['ok'], json_encode($result));
        $linked = $result['content'][0]['inner'][0]['body'][0]['content'][1];
        $this->assertSame('bar', $linked['text']);
        $this->assertSame('link', $linked['marks'][0]['type']);
    }

    public function test_position_propagates_failure_from_inner_bard_already_linked(): void
    {
        // The Bard layer's already-linked guard must surface through the
        // Replicator navigation untouched. Same failure shape — reason +
        // blocking_href both forwarded.
        $sets = [[
            'type' => 'text_block',
            'id' => 'set-1',
            'body' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Redis Setup Guide',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::other']]],
                ]],
            ]],
        ]];

        $result = BardLinkInserter::insertLinkAtPositionInReplicator(
            $sets,
            'Redis Setup Guide',
            self::HREF,
            replicatorPath: [['set_index' => 0, 'key' => 'body']],
            paragraphPath: [0],
            charStart: 0,
            charEnd: 17,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('crosses_existing_link', $result['reason']);
        $this->assertSame('statamic://entry::other', $result['blocking_href']);
    }

    // ─── processAllInReplicator (public on Router post-extraction) ─────

    public function test_process_all_wraps_every_occurrence_across_nested_bard(): void
    {
        $sets = [
            [
                'type' => 'text_block',
                'id' => 'set-1',
                'body' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'foo and foo again']]],
                ],
            ],
            [
                'type' => 'text_block',
                'id' => 'set-2',
                'body' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one more foo here']]],
                ],
            ],
        ];

        $result = ReplicatorLinkRouter::processAllInReplicator($sets, 'foo', self::HREF, false);

        $this->assertNotNull($result);
        $marksSet1 = $this->collectLinkMarks($result[0]['body']);
        $marksSet2 = $this->collectLinkMarks($result[1]['body']);
        $this->assertSame(2, $marksSet1, 'set 1 must wrap both foo occurrences');
        $this->assertSame(1, $marksSet2, 'set 2 must wrap the single foo occurrence');
    }

    public function test_process_all_returns_null_when_no_anchor_in_any_set(): void
    {
        $sets = [[
            'type' => 'text_block',
            'id' => 'set-1',
            'body' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'no needle here']]],
            ],
        ]];

        $result = ReplicatorLinkRouter::processAllInReplicator($sets, 'absent', self::HREF, false);

        $this->assertNull($result);
    }

    // ─── countLinksToInReplicator (public on Router post-extraction) ──

    public function test_count_links_aggregates_across_nested_replicator_levels(): void
    {
        $sets = [
            [
                'type' => 'outer',
                'id' => 'o-1',
                'inner' => [
                    [
                        'type' => 'text_block',
                        'id' => 'i-1',
                        'body' => [[
                            'type' => 'paragraph',
                            'content' => [[
                                'type' => 'text',
                                'text' => 'foo',
                                'marks' => [['type' => 'link', 'attrs' => ['href' => self::HREF]]],
                            ]],
                        ]],
                    ],
                ],
            ],
            [
                'type' => 'text_block',
                'id' => 't-1',
                'body' => [[
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'bar',
                            'marks' => [['type' => 'link', 'attrs' => ['href' => self::HREF]]],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'baz',
                            'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::other']]],
                        ],
                    ],
                ]],
            ],
        ];

        $this->assertSame(2, ReplicatorLinkRouter::countLinksToInReplicator($sets, self::HREF));
        $this->assertSame(1, ReplicatorLinkRouter::countLinksToInReplicator($sets, 'statamic://entry::other'));
        $this->assertSame(0, ReplicatorLinkRouter::countLinksToInReplicator($sets, 'statamic://entry::missing'));
    }

    // ─── helpers ──────────────────────────────────────────────────────

    private function collectLinkMarks(array $bardContent): int
    {
        $count = 0;
        foreach ($bardContent as $node) {
            if (isset($node['marks'])) {
                foreach ($node['marks'] as $mark) {
                    if (($mark['type'] ?? '') === 'link') {
                        $count++;
                    }
                }
            }
            if (isset($node['content']) && is_array($node['content'])) {
                $count += $this->collectLinkMarks($node['content']);
            }
        }

        return $count;
    }
}
