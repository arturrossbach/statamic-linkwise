<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BardLinkInserter;
use Arturrossbach\Linkwise\Support\UrlHelper;
use Arturrossbach\Linkwise\UrlChanger\UrlReplacer;
use PHPUnit\Framework\TestCase;

/**
 * Pre-commit pre-shipped duplicate of the auditMutatorParity +
 * auditInsertParity audit cases. The audit lives in linkwise:audit
 * and runs against a real Statamic app (prose-peak-test); these
 * unit tests run in CI / pre-commit hooks WITHOUT a Statamic app,
 * so a future code change can never break Bard/Markdown/Replicator
 * parity without phpunit going red.
 *
 * If you change one path's mutator/insert semantics intentionally,
 * the corresponding case here MUST be updated alongside the audit
 * case in src/Commands/AuditCommand.php — same content, different
 * harness. Memory: feedback_mutator_parity.md.
 */
class MutatorAndInsertParityTest extends TestCase
{
    /* ─────────── MUTATOR PARITY (replace / unlink) ─────────── */

    public function test_mutator_parity_match_anchor_and_index(): void
    {
        $this->assertMutatorParity(
            anchorInDoc: 'OnlyAnchor',
            argOldUrl: 'https://example.com/parity',
            argNewUrl: 'https://example.com/replaced',
            argIndex: 0,
            expectedAnchor: 'OnlyAnchor',
            expectReplaced: true,
            expectChanged: true,
        );
    }

    public function test_mutator_parity_index_out_of_bounds_skips(): void
    {
        $this->assertMutatorParity(
            anchorInDoc: 'OnlyAnchor',
            argOldUrl: 'https://example.com/parity',
            argNewUrl: 'https://example.com/replaced',
            argIndex: 5,
            expectedAnchor: null,
            expectReplaced: false,
            expectChanged: false,
        );
    }

    public function test_mutator_parity_anchor_mismatch_skips(): void
    {
        $this->assertMutatorParity(
            anchorInDoc: 'WrongAnchor',
            argOldUrl: 'https://example.com/parity',
            argNewUrl: 'https://example.com/replaced',
            argIndex: 0,
            expectedAnchor: 'ScannedAnchor',
            expectReplaced: false,
            expectChanged: false,
        );
    }

    public function test_mutator_parity_anchor_matches_explicitly(): void
    {
        $this->assertMutatorParity(
            anchorInDoc: 'KnownAnchor',
            argOldUrl: 'https://example.com/parity',
            argNewUrl: 'https://example.com/replaced',
            argIndex: 0,
            expectedAnchor: 'KnownAnchor',
            expectReplaced: true,
            expectChanged: true,
        );
    }

    public function test_mutator_parity_unlink_removes_mark_keeps_text(): void
    {
        $this->assertMutatorParity(
            anchorInDoc: 'KeepMyText',
            argOldUrl: 'https://example.com/parity',
            argNewUrl: UrlHelper::UNLINK,
            argIndex: 0,
            expectedAnchor: 'KeepMyText',
            expectReplaced: true,
            expectChanged: true,
        );
    }

    public function test_mutator_parity_legacy_call_no_anchor_arg(): void
    {
        $this->assertMutatorParity(
            anchorInDoc: 'AnyAnchor',
            argOldUrl: 'https://example.com/parity',
            argNewUrl: 'https://example.com/replaced',
            argIndex: 0,
            expectedAnchor: null,
            expectReplaced: true,
            expectChanged: true,
        );
    }

    /* ─────────── INSERT PARITY ─────────── */

    public function test_insert_parity_anchor_present_fresh_text(): void
    {
        $this->assertInsertParity('plain', 'AnchorWord', expectInserted: true, expectChanged: true);
    }

    public function test_insert_parity_anchor_missing(): void
    {
        $this->assertInsertParity('plain-without-anchor', 'AnchorWord', expectInserted: false, expectChanged: false);
    }

    public function test_insert_parity_anchor_already_linked(): void
    {
        $this->assertInsertParity('already-linked', 'AnchorWord', expectInserted: false, expectChanged: false);
    }

    public function test_insert_parity_anchor_twice_single_insert_wraps_first(): void
    {
        $this->assertInsertParity('twice', 'AnchorWord', expectInserted: true, expectChanged: true);
    }

    /* ─────────── context-fingerprint guard (visual truth, 2026-05-10) ─────────── */

    public function test_insert_parity_context_picks_correct_occurrence_when_anchor_appears_twice(): void
    {
        // Two paragraphs each containing "AnchorWord". The captured
        // sentence belongs to the SECOND paragraph. With the fingerprint
        // guard, the wrap MUST land on the second one — not the first
        // (which would be the silent-wrong-position bug). Same outcome
        // required across Bard / Markdown / Replicator.
        $href = 'https://example.com/insert-target';
        $anchor = 'AnchorWord';
        $context = 'cached, ohne die Invalidierungs-Strategie mitzudenken, baut AnchorWord';

        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => "$anchor. Ein neuer Anfangssatz."],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'cached, ohne die Invalidierungs-Strategie mitzudenken, baut '.$anchor],
            ]],
        ];
        $md = "$anchor. Ein neuer Anfangssatz.\n\ncached, ohne die Invalidierungs-Strategie mitzudenken, baut $anchor";
        $replicator = [
            ['id' => 'set-1', 'type' => 'text_block', 'enabled' => true, 'body' => $bard],
        ];

        $bardOut = BardLinkInserter::insertLinkWithHref($bard, $anchor, $href, false, $context);
        $mdOut = BardLinkInserter::insertLinkIntoMarkdown($md, $anchor, $href, false, $context);
        $repOut = BardLinkInserter::processReplicatorWithHref($replicator, $anchor, $href, false, $context);

        $this->assertNotNull($bardOut, 'Bard: should insert when context matches');
        $this->assertNotNull($mdOut, 'Markdown: should insert when context matches');
        $this->assertNotNull($repOut, 'Replicator: should insert when context matches');

        // Bard: first paragraph's sole text-node should NOT have a link mark;
        // second paragraph's wrapped node should.
        $firstWrapped = !empty($bardOut[0]['content'][0]['marks'] ?? null);
        $secondWrapped = false;
        foreach ($bardOut[1]['content'] ?? [] as $c) {
            if (($c['text'] ?? '') === $anchor && !empty($c['marks'] ?? null)) $secondWrapped = true;
        }
        $this->assertFalse($firstWrapped, 'Bard: first occurrence (prepended) MUST NOT be wrapped');
        $this->assertTrue($secondWrapped, 'Bard: second occurrence (in matched sentence) MUST be wrapped');

        // Markdown: scan for [AnchorWord](url) occurrences; should land in the
        // second paragraph's text only.
        $linkPattern = '/\['.$anchor.'\]\(' . preg_quote($href, '/') . '\)/u';
        preg_match_all($linkPattern, $mdOut, $matches, PREG_OFFSET_CAPTURE);
        $this->assertCount(1, $matches[0], 'Markdown: exactly one wrapping');
        $wrappedAt = $matches[0][0][1];
        $secondParaStart = strpos($md, 'cached');
        $this->assertGreaterThanOrEqual($secondParaStart, $wrappedAt, 'Markdown: wrap must land in the second paragraph (matched sentence), not the first');
    }

    public function test_insert_parity_context_not_in_doc_skips(): void
    {
        // Captured sentence no longer present in the entry → refuse to wrap
        // anything (= what the user wants instead of silent wrong wrap).
        $href = 'https://example.com/insert-target';
        $anchor = 'AnchorWord';
        $context = 'this exact sentence does not exist anywhere in the doc';

        $bard = [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => "Lorem $anchor ipsum"],
            ]],
        ];
        $md = "Lorem $anchor ipsum";
        $replicator = [
            ['id' => 'set-1', 'type' => 'text_block', 'enabled' => true, 'body' => $bard],
        ];

        $this->assertNull(BardLinkInserter::insertLinkWithHref($bard, $anchor, $href, false, $context), 'Bard: must skip when context absent');
        $this->assertNull(BardLinkInserter::insertLinkIntoMarkdown($md, $anchor, $href, false, $context), 'Markdown: must skip when context absent');
        $this->assertNull(BardLinkInserter::processReplicatorWithHref($replicator, $anchor, $href, false, $context), 'Replicator: must skip when context absent');
    }

    /* ─────────── helpers ─────────── */

    protected function assertMutatorParity(
        string $anchorInDoc,
        string $argOldUrl,
        string $argNewUrl,
        int $argIndex,
        ?string $expectedAnchor,
        bool $expectReplaced,
        bool $expectChanged,
    ): void {
        $oldUrl = 'https://example.com/parity';
        $bard = $this->mutatorBuildBard($anchorInDoc, $oldUrl);
        $md = $this->mutatorBuildMarkdown($anchorInDoc, $oldUrl);
        $replicator = $this->mutatorBuildReplicator($anchorInDoc, $oldUrl);

        $replacer = new UrlReplacer();
        [$bardOut, $bardReplaced] = $replacer->replaceNthInBard($bard, 'example.com', $argOldUrl, $argNewUrl, $argIndex, $expectedAnchor);
        [$mdOut, $mdReplaced] = $replacer->replaceNthInMarkdown($md, $argOldUrl, $argNewUrl, $argIndex, $expectedAnchor);
        [$repOut, $repReplaced] = $replacer->replaceNthInReplicator($replicator, 'example.com', $argOldUrl, $argNewUrl, $argIndex, $expectedAnchor);

        $this->assertSame($expectReplaced, $bardReplaced, 'Bard replaced flag');
        $this->assertSame($expectReplaced, $mdReplaced, 'Markdown replaced flag');
        $this->assertSame($expectReplaced, $repReplaced, 'Replicator replaced flag');

        $this->assertSame($expectChanged, $bard !== $bardOut, 'Bard content-changed');
        $this->assertSame($expectChanged, $md !== $mdOut, 'Markdown content-changed');
        $this->assertSame($expectChanged, $replicator !== $repOut, 'Replicator content-changed');
    }

    protected function assertInsertParity(string $variant, string $anchor, bool $expectInserted, bool $expectChanged): void
    {
        $href = 'https://example.com/insert-target';
        $bard = $this->insertBuildBard($variant, $anchor);
        $md = $this->insertBuildMarkdown($variant, $anchor);
        $replicator = $this->insertBuildReplicator($variant, $anchor);

        $bardOut = BardLinkInserter::insertLinkWithHref($bard, $anchor, $href);
        $mdOut = BardLinkInserter::insertLinkIntoMarkdown($md, $anchor, $href);
        $repOut = BardLinkInserter::processReplicatorWithHref($replicator, $anchor, $href);

        $this->assertSame($expectInserted, $bardOut !== null, 'Bard inserted');
        $this->assertSame($expectInserted, $mdOut !== null, 'Markdown inserted');
        $this->assertSame($expectInserted, $repOut !== null, 'Replicator inserted');

        $this->assertSame($expectChanged, $bardOut !== null && $bardOut !== $bard, 'Bard content-changed');
        $this->assertSame($expectChanged, $mdOut !== null && $mdOut !== $md, 'Markdown content-changed');
        $this->assertSame($expectChanged, $repOut !== null && $repOut !== $replicator, 'Replicator content-changed');
    }

    protected function mutatorBuildBard(string $anchor, string $url): array
    {
        return [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Before. '],
                ['type' => 'text', 'text' => $anchor, 'marks' => [['type' => 'link', 'attrs' => ['href' => $url]]]],
                ['type' => 'text', 'text' => '. After.'],
            ]],
        ];
    }

    protected function mutatorBuildMarkdown(string $anchor, string $url): string
    {
        return "Before. [$anchor]($url). After.";
    }

    protected function mutatorBuildReplicator(string $anchor, string $url): array
    {
        return [
            ['id' => 'set-1', 'type' => 'text_block', 'enabled' => true,
             'body' => $this->mutatorBuildBard($anchor, $url)],
        ];
    }

    protected function insertBuildBard(string $variant, string $anchor): array
    {
        return match ($variant) {
            'plain' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => "Lorem $anchor ipsum dolor."],
            ]]],
            'plain-without-anchor' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Lorem ipsum dolor sit amet.'],
            ]]],
            'already-linked' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Lorem '],
                ['type' => 'text', 'text' => $anchor, 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://other.example/already']]]],
                ['type' => 'text', 'text' => ' ipsum dolor.'],
            ]]],
            'twice' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => "Lorem $anchor ipsum $anchor dolor."],
            ]]],
        };
    }

    protected function insertBuildMarkdown(string $variant, string $anchor): string
    {
        return match ($variant) {
            'plain' => "Lorem $anchor ipsum dolor.",
            'plain-without-anchor' => 'Lorem ipsum dolor sit amet.',
            'already-linked' => "Lorem [$anchor](https://other.example/already) ipsum dolor.",
            'twice' => "Lorem $anchor ipsum $anchor dolor.",
        };
    }

    protected function insertBuildReplicator(string $variant, string $anchor): array
    {
        return [
            ['id' => 'set-1', 'type' => 'text_block', 'enabled' => true,
             'body' => $this->insertBuildBard($variant, $anchor)],
        ];
    }
}
