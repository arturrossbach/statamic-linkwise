<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BardLinkInserter;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for REV-RL-01 dead-plumbing sweep.
 *
 * Before the position-passing refactor (Bug 17-20 root-fix, branch
 * `refactor/relink-position-passing`, master commits ab65f86..d317327),
 * BardLinkInserter accepted `?int $anchorOffsetInContext` as a 6th
 * parameter that narrowed the context-range to a single position within
 * the captured sentence. After the refactor, Re-Link no longer needs that
 * narrowing — Step A returns the EXACT position and Step C consumes it
 * directly via insertLinkAtPosition*. The parameter is dead in the backend
 * (all 9 BardLinkInserter signatures + RelinkService + RelinkController),
 * but the SAME field-name `anchor_offset_in_context` is still alive in
 * the frontend for highlight-rendering (different purpose, same name —
 * Pass 3 documented this naming-smell).
 *
 * REV-RL-01 removes the backend param chain. These tests pin down the
 * dead-equivalence: passing the param produces the SAME output as not
 * passing it, for any value. After the sweep the param is gone entirely.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-RL-01
 * @see architectural_health.md Klasse 1 / Re-Link section
 */
class AnchorOffsetInContextSweepTest extends TestCase
{
    private const HREF = 'https://example.com/target';

    private function paraWithAnchor(string $anchor): array
    {
        return [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => "Lorem $anchor ipsum."],
        ]]];
    }

    /**
     * After the sweep, insertLinkWithHref no longer accepts the 6th
     * param. This characterization test only needs to confirm the
     * function still wraps correctly with the 5-arg call shape.
     */
    public function test_insertLinkWithHref_works_with_post_sweep_signature(): void
    {
        $bard = $this->paraWithAnchor('AnchorWord');

        $out = BardLinkInserter::insertLinkWithHref(
            $bard,
            'AnchorWord',
            self::HREF,
            false,
            'Lorem AnchorWord ipsum.',
        );

        $this->assertNotNull($out);
        $this->assertSame('AnchorWord', $out[0]['content'][1]['text'] ?? null);
        $this->assertNotEmpty($out[0]['content'][1]['marks'] ?? null);
    }

    /** Same for the Replicator entry point. */
    public function test_processReplicatorWithHref_works_with_post_sweep_signature(): void
    {
        $rep = [['id' => 's1', 'type' => 'text_block', 'enabled' => true,
                 'body' => $this->paraWithAnchor('Redis')]];

        $out = BardLinkInserter::processReplicatorWithHref(
            $rep,
            'Redis',
            self::HREF,
            false,
            'Lorem Redis ipsum.',
        );

        $this->assertNotNull($out);
        $body = $out[0]['body'];
        $this->assertSame('Redis', $body[0]['content'][1]['text'] ?? null);
        $this->assertNotEmpty($body[0]['content'][1]['marks'] ?? null);
    }

    /** canInsertLinkIntoBardContent dry-run after sweep. */
    public function test_canInsertLinkIntoBardContent_works_with_post_sweep_signature(): void
    {
        $bard = $this->paraWithAnchor('Redis');

        $result = BardLinkInserter::canInsertLinkIntoBardContent(
            $bard,
            'Redis',
            self::HREF,
            false,
            'Lorem Redis ipsum.',
        );

        $this->assertTrue($result['ok'] ?? false);
    }

    /**
     * Source-pattern test: after the sweep, no anchorOffsetInContext param
     * remains in the backend signature chain. Frontend usage in
     * SuggestedPhrase.vue / DashboardController response shape stays — same
     * field name, different purpose (highlight-rendering, not insert
     * guard).
     */
    public function test_backend_no_longer_references_anchorOffsetInContext_param(): void
    {
        $backendFiles = [
            'src/Relink/RelinkService.php',
            'src/Support/BardLinkInserter.php',
            'src/Http/Controllers/RelinkController.php',
        ];

        foreach ($backendFiles as $relative) {
            $source = file_get_contents(__DIR__.'/../../'.$relative);
            $this->assertStringNotContainsString(
                '$anchorOffsetInContext',
                $source,
                "$relative still declares/passes \$anchorOffsetInContext — sweep incomplete"
            );
        }

        // RelinkController must not validate the param either (it was
        // accepting it from the frontend and forwarding to service).
        $rcSource = file_get_contents(__DIR__.'/../../src/Http/Controllers/RelinkController.php');
        $this->assertStringNotContainsString(
            "'anchor_offset_in_context'",
            $rcSource,
            'RelinkController still validates anchor_offset_in_context — frontend may still send it but backend must ignore'
        );
    }

    /**
     * DashboardController STILL emits anchor_offset_in_context in the
     * frontend-facing response (highlight-rendering coordinate). This
     * test pins that down so the sweep doesn't accidentally remove it.
     */
    public function test_dashboard_controller_still_emits_anchor_offset_for_frontend_highlights(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Http/Controllers/DashboardController.php');
        $this->assertStringContainsString(
            "'anchor_offset_in_context'",
            $source,
            'DashboardController must continue to emit anchor_offset_in_context for frontend highlight-rendering — '.
            'this is a different purpose than the backend dead-plumbing'
        );
    }
}
