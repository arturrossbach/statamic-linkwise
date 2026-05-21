<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\OccurrenceStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OccurrenceStatus — the helper that classifies each
 * occurrence of a keyword as 'would_link' / 'linked_to_target' /
 * 'linked_elsewhere' for the Auto-Linking Preview in multi-mode.
 *
 * Each occurrence must be evaluated INDIVIDUALLY: the first "database" can
 * already be linked to our target while the next "database" is plain text
 * and ready to be linked. Entry-level checks would label both the same and
 * mislead the user (the bug we're fixing).
 */
class OccurrenceStatusTest extends TestCase
{
    // ─── Markdown ───────────────────────────────────────────────────────

    public function test_markdown_position_inside_link_to_target_returns_linked_to_target(): void
    {
        $md = 'See the [database](statamic://entry::TARGET) docs and other database notes.';
        // Position of "database" inside the link
        $pos = mb_strpos($md, 'database');

        $status = OccurrenceStatus::forMarkdownPosition($md, $pos, mb_strlen('database'), 'statamic://entry::TARGET');

        $this->assertSame('linked_to_target', $status);
    }

    public function test_markdown_position_inside_link_elsewhere_returns_linked_elsewhere(): void
    {
        $md = 'See the [database](https://wikipedia.org/db) and other database notes.';
        $pos = mb_strpos($md, 'database');

        $status = OccurrenceStatus::forMarkdownPosition($md, $pos, mb_strlen('database'), 'statamic://entry::TARGET');

        $this->assertSame('linked_elsewhere', $status);
    }

    public function test_markdown_position_outside_links_returns_would_link(): void
    {
        $md = 'Plain text database without any link wrapping.';
        $pos = mb_strpos($md, 'database');

        $status = OccurrenceStatus::forMarkdownPosition($md, $pos, mb_strlen('database'), 'statamic://entry::TARGET');

        $this->assertSame('would_link', $status);
    }

    public function test_markdown_first_occurrence_linked_second_unlinked_classifies_independently(): void
    {
        $md = '[database](statamic://entry::TARGET) here. Then DATABASE YEAH unlinked.';

        $first = mb_stripos($md, 'database', 0);
        $second = mb_stripos($md, 'database', $first + 8);

        $statusFirst = OccurrenceStatus::forMarkdownPosition($md, $first, 8, 'statamic://entry::TARGET');
        $statusSecond = OccurrenceStatus::forMarkdownPosition($md, $second, 8, 'statamic://entry::TARGET');

        $this->assertSame('linked_to_target', $statusFirst);
        $this->assertSame('would_link', $statusSecond, 'The second occurrence is plain text — must be classified independently');
    }

    // ─── Bard ──────────────────────────────────────────────────────────

    public function test_bard_text_node_with_link_to_target_returns_linked_to_target(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'database',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::TARGET']]],
                    ],
                ],
            ],
        ];

        $status = OccurrenceStatus::forBardTextNode($bard[0]['content'][0], 'statamic://entry::TARGET');

        $this->assertSame('linked_to_target', $status);
    }

    public function test_bard_text_node_with_other_link_returns_linked_elsewhere(): void
    {
        $bard = [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'database',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://wikipedia.org/db']]],
                    ],
                ],
            ],
        ];

        $status = OccurrenceStatus::forBardTextNode($bard[0]['content'][0], 'statamic://entry::TARGET');

        $this->assertSame('linked_elsewhere', $status);
    }

    public function test_bard_unlinked_text_node_returns_would_link(): void
    {
        $node = ['type' => 'text', 'text' => 'database is plain'];

        $status = OccurrenceStatus::forBardTextNode($node, 'statamic://entry::TARGET');

        $this->assertSame('would_link', $status);
    }

    public function test_bard_text_node_with_non_link_marks_returns_would_link(): void
    {
        $node = ['type' => 'text', 'text' => 'database', 'marks' => [['type' => 'bold']]];

        $status = OccurrenceStatus::forBardTextNode($node, 'statamic://entry::TARGET');

        $this->assertSame('would_link', $status);
    }
}
