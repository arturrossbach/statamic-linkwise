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
