<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\BardLinkInserter;
use PHPUnit\Framework\TestCase;

/**
 * Safety net for the find-first-walker class of bugs in Outbound/Inbound/
 * AutoLink/ApplyRule insert paths.
 *
 * The context-fingerprint guard inside insertLinkWithHref/
 * insertLinkIntoMarkdown/processReplicatorWithHref is the only thing that
 * prevents "user clicks suggestion → link lands on a different occurrence"
 * (= "Falsche Wiederholung getroffen" in the user's words).
 *
 * MutatorAndInsertParityTest already covers the obvious cases with a single
 * hand-picked seed each. This file widens that to:
 *   - F1: multi-paragraph disambiguation (anchor 3× in 3 paragraphs, pick the
 *     middle/last) — Bard + Markdown + Replicator, with full position-asserts
 *     for Replicator (existing tests only assertNotNull there).
 *   - F1b: multi-occurrence inside a single paragraph (anchor 3× in one para,
 *     pick the middle).
 *   - F5: case-mixed + multi-occurrence + context.
 *   - F8: punctuation-adjacent ambiguity.
 *   - F6: Replicator with the anchor in two sets, context routes to one.
 *   - F2 (documented): identical near-duplicate paragraphs — today's behavior
 *     is "first paragraph wins" because the guard cannot disambiguate; this
 *     test pins that down so we notice if it changes. The latent silent-
 *     wrong-wrap risk is tracked in architectural_health.md.
 *
 * If any of these tests goes red, a NEW occurrence of the find-first-walker
 * bug class has shipped. See architectural_health.md class 1.
 */
class InsertContextDisambiguationTest extends TestCase
{
    private const HREF = 'https://example.com/target';

    /* ────────────────────────── F1 — Multi-paragraph ────────────────────────── */

    /** Anchor in 3 paragraphs; context matches the middle one. */
    public function test_f1_bard_three_paragraphs_picks_middle(): void
    {
        $anchor = 'Redis';
        $context = 'Cache-Layer mit Redis aufbauen.';
        $bard = [
            $this->bardPara("Wir starten mit $anchor."),
            $this->bardPara("Cache-Layer mit $anchor aufbauen."),
            $this->bardPara("$anchor ist eine Datenbank."),
        ];

        $out = BardLinkInserter::insertLinkWithHref($bard, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        $this->assertBardWrappedParagraph($out, expectedIndex: 1, anchor: $anchor);
    }

    /** Anchor in 3 paragraphs; context matches the last. */
    public function test_f1_bard_three_paragraphs_picks_last(): void
    {
        $anchor = 'Redis';
        $context = 'Verbreitet im Caching: Redis.';
        $bard = [
            $this->bardPara("Wir starten mit $anchor."),
            $this->bardPara("Cache-Layer mit $anchor aufbauen."),
            $this->bardPara("Verbreitet im Caching: $anchor."),
        ];

        $out = BardLinkInserter::insertLinkWithHref($bard, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        $this->assertBardWrappedParagraph($out, expectedIndex: 2, anchor: $anchor);
    }

    /** Markdown equivalent of F1: 3 lines, pick middle. */
    public function test_f1_markdown_three_paragraphs_picks_middle(): void
    {
        $anchor = 'Redis';
        $context = 'Cache-Layer mit Redis aufbauen.';
        $md = "Wir starten mit $anchor.\n\nCache-Layer mit $anchor aufbauen.\n\n$anchor ist eine Datenbank.";

        $out = BardLinkInserter::insertLinkIntoMarkdown($md, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        $this->assertMarkdownWrappedAtParagraph($out, $md, expectedParaIndex: 1, anchor: $anchor);
    }

    /** Replicator equivalent of F1: 3 sets each with one paragraph, pick last. */
    public function test_f1_replicator_three_sets_picks_last(): void
    {
        $anchor = 'Redis';
        $context = 'Verbreitet im Caching: Redis.';
        $replicator = [
            $this->replicatorSet('s1', [$this->bardPara("Wir starten mit $anchor.")]),
            $this->replicatorSet('s2', [$this->bardPara("Cache-Layer mit $anchor aufbauen.")]),
            $this->replicatorSet('s3', [$this->bardPara("Verbreitet im Caching: $anchor.")]),
        ];

        $out = BardLinkInserter::processReplicatorWithHref($replicator, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        $this->assertReplicatorWrappedInSet($out, expectedSetIndex: 2, expectedParaIndex: 0, anchor: $anchor);
        $this->assertReplicatorNotWrappedInSet($out, setIndex: 0, anchor: $anchor);
        $this->assertReplicatorNotWrappedInSet($out, setIndex: 1, anchor: $anchor);
    }

    /* ─────────────────── F1b — Multi-occurrence in one paragraph ─────────────────── */

    /** Same paragraph contains the anchor 3×; context narrows to the middle. */
    public function test_f1b_bard_three_occurrences_one_paragraph_picks_middle(): void
    {
        $anchor = 'Redis';
        $context = 'erweitert um eine Redis-Replikation';
        $bard = [
            $this->bardPara("$anchor läuft lokal. Setup erweitert um eine $anchor-Replikation. Später skaliert $anchor."),
        ];

        $out = BardLinkInserter::insertLinkWithHref($bard, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        // The middle "Redis" must be wrapped, not the first or third.
        $this->assertBardWrappedOccurrenceInParagraph($out, paraIndex: 0, anchor: $anchor, expectedOccurrenceIndex: 1);
    }

    /** Markdown variant of F1b — same paragraph anchor 3×, pick middle. */
    public function test_f1b_markdown_three_occurrences_one_paragraph_picks_middle(): void
    {
        $anchor = 'Redis';
        $context = 'erweitert um eine Redis-Replikation';
        $md = "$anchor läuft lokal. Setup erweitert um eine $anchor-Replikation. Später skaliert $anchor.";

        $out = BardLinkInserter::insertLinkIntoMarkdown($md, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        // Exactly one wrap, and it must be at the middle occurrence position.
        $linkPattern = '/\['.preg_quote($anchor, '/').'\]\('.preg_quote(self::HREF, '/').'\)/u';
        preg_match_all($linkPattern, $out, $matches, PREG_OFFSET_CAPTURE);
        $this->assertCount(1, $matches[0], 'Markdown: exactly one wrap expected');
        // Wrap position must be inside the captured context substring.
        $contextStartInMd = mb_strpos($md, $context);
        $contextEndInMd = $contextStartInMd + mb_strlen($context);
        $wrappedAt = $matches[0][0][1];
        $this->assertGreaterThanOrEqual($contextStartInMd, $wrappedAt, 'Wrap must land inside the captured context');
        $this->assertLessThan($contextEndInMd + mb_strlen($anchor), $wrappedAt, 'Wrap must not exceed context range');
    }

    /* ───────────────────────────── F5 — Case ─────────────────────────────────── */

    /**
     * Anchor exists in two casings ("Redis" and "REDIS") in different
     * paragraphs. Context matches the all-caps line. Default insertLinkWithHref
     * is case-insensitive — the wrap MUST land in the matching paragraph,
     * not the first occurrence.
     */
    public function test_f5_bard_case_mixed_multi_paragraph(): void
    {
        $anchor = 'Redis';
        $context = 'In Caps: REDIS macht den Job.';
        $bard = [
            $this->bardPara('Wir starten mit Redis.'),
            $this->bardPara('In Caps: REDIS macht den Job.'),
        ];

        $out = BardLinkInserter::insertLinkWithHref($bard, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        $this->assertBardWrappedParagraph($out, expectedIndex: 1, anchor: 'REDIS');
    }

    /* ───────────────────────────── F8 — Punctuation ──────────────────────────── */

    /**
     * Anchor adjacent to different punctuation in two paragraphs. Context
     * distinguishes which paragraph the user meant.
     */
    public function test_f8_bard_punctuation_adjacent_disambiguates(): void
    {
        $anchor = 'Redis';
        $context = 'mit Redis, dem Cache.';
        $bard = [
            $this->bardPara('Wir starten mit Redis. Punkt am Ende.'),
            $this->bardPara('Setup mit Redis, dem Cache.'),
        ];

        $out = BardLinkInserter::insertLinkWithHref($bard, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        $this->assertBardWrappedParagraph($out, expectedIndex: 1, anchor: $anchor);
    }

    /* ──────────────────── F6 — Replicator set routing ──────────────────────── */

    /**
     * Two Replicator sets each contain a Bard paragraph with the anchor;
     * the context substring sits only in set #2. Walker must wrap set #2.
     */
    public function test_f6_replicator_two_sets_routes_to_context_match(): void
    {
        $anchor = 'Redis';
        $context = 'Schritt zwei: Redis als Cache anbinden.';
        $replicator = [
            $this->replicatorSet('s1', [$this->bardPara("Erster Schritt: $anchor lokal aufsetzen.")]),
            $this->replicatorSet('s2', [$this->bardPara("Schritt zwei: $anchor als Cache anbinden.")]),
        ];

        $out = BardLinkInserter::processReplicatorWithHref($replicator, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out);
        $this->assertReplicatorWrappedInSet($out, expectedSetIndex: 1, expectedParaIndex: 0, anchor: $anchor);
        $this->assertReplicatorNotWrappedInSet($out, setIndex: 0, anchor: $anchor);
    }

    /* ─────────────── F2 — Near-duplicate paragraphs (documented) ─────────── */

    /**
     * Two paragraphs are character-for-character identical. The
     * context-fingerprint guard cannot disambiguate them; today's behavior
     * is "first paragraph wins". This test pins that down so we notice if it
     * silently changes.
     *
     * This is a LATENT silent-wrong-wrap risk — if the user really meant the
     * second occurrence, they get the wrong one. Tracked as class 1.x in
     * architectural_health.md. NOT fixed here (scope: safety net only).
     */
    public function test_f2_bard_identical_paragraphs_first_wins_documented(): void
    {
        $anchor = 'Redis';
        $duplicateSentence = "Mehr dazu in unserem Guide zu $anchor.";
        $context = "Mehr dazu in unserem Guide zu $anchor.";
        $bard = [
            $this->bardPara($duplicateSentence),
            $this->bardPara($duplicateSentence),
        ];

        $out = BardLinkInserter::insertLinkWithHref($bard, $anchor, self::HREF, false, $context);

        $this->assertNotNull($out, 'Today: walker picks first paragraph on ambiguity');
        $this->assertBardWrappedParagraph($out, expectedIndex: 0, anchor: $anchor);
        // If you arrived here from a red test: the walker now disambiguates
        // identical paragraphs differently. That is likely the desired fix
        // (refuse with context_ambiguous reason). Update this test + flip
        // assertion accordingly, and remove the class-1.x debt entry in
        // architectural_health.md.
    }

    /* ─────────────────────── refusal — context_mismatch ─────────────────── */

    /** Context references a sentence not present anywhere; wrap must refuse. */
    public function test_refuses_when_context_truly_absent_bard(): void
    {
        $anchor = 'Redis';
        $context = 'Dieser Satz steht nirgends im Dokument.';
        $bard = [$this->bardPara("Wir starten mit $anchor.")];

        $out = BardLinkInserter::insertLinkWithHref($bard, $anchor, self::HREF, false, $context);

        $this->assertNull($out, 'Refuse silently when context is stale; caller surfaces toast');
    }

    public function test_refuses_when_context_truly_absent_markdown(): void
    {
        $anchor = 'Redis';
        $context = 'Dieser Satz steht nirgends.';
        $md = "Wir starten mit $anchor.";

        $out = BardLinkInserter::insertLinkIntoMarkdown($md, $anchor, self::HREF, false, $context);

        $this->assertNull($out);
    }

    public function test_refuses_when_context_truly_absent_replicator(): void
    {
        $anchor = 'Redis';
        $context = 'Dieser Satz steht nirgends.';
        $replicator = [$this->replicatorSet('s1', [$this->bardPara("Wir starten mit $anchor.")])];

        $out = BardLinkInserter::processReplicatorWithHref($replicator, $anchor, self::HREF, false, $context);

        $this->assertNull($out);
    }

    /* ───────────────────────────── helpers ──────────────────────────────── */

    /** Build a single-text-node Bard paragraph. */
    private function bardPara(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** Wrap a Bard fragment as a single Replicator set. */
    private function replicatorSet(string $id, array $bardBody): array
    {
        return ['id' => $id, 'type' => 'text_block', 'enabled' => true, 'body' => $bardBody];
    }

    /**
     * Assert that EXACTLY the paragraph at $expectedIndex contains a wrapped
     * occurrence of $anchor (any node, marks-non-empty) and no other paragraph
     * does.
     */
    private function assertBardWrappedParagraph(array $bardOut, int $expectedIndex, string $anchor): void
    {
        foreach ($bardOut as $i => $para) {
            $wrapped = $this->paragraphHasWrappedAnchor($para, $anchor);
            if ($i === $expectedIndex) {
                $this->assertTrue($wrapped, "Paragraph #$i should contain wrapped anchor '$anchor'");
            } else {
                $this->assertFalse($wrapped, "Paragraph #$i must NOT contain a wrapped anchor (only #$expectedIndex should)");
            }
        }
    }

    /**
     * Assert that within a paragraph, the N-th occurrence of $anchor (0-based)
     * is the one wrapped. The walker splits the source text-node only at the
     * wrap boundary, so the unwrapped occurrences stay embedded in their
     * surrounding text nodes. We compute occurrence positions by
     * concatenating the paragraph's text and matching the wrap char-offset
     * against the N-th mb_stripos.
     */
    private function assertBardWrappedOccurrenceInParagraph(array $bardOut, int $paraIndex, string $anchor, int $expectedOccurrenceIndex): void
    {
        $para = $bardOut[$paraIndex] ?? null;
        $this->assertNotNull($para, "Paragraph #$paraIndex missing");

        // Walk content left-to-right, tracking concatenated char offset; find
        // the text-node that IS the anchor and has marks. Its starting offset
        // is the wrap position.
        $offset = 0;
        $wrapOffset = null;
        $fullText = '';
        foreach ($para['content'] ?? [] as $node) {
            if (($node['type'] ?? null) !== 'text') continue;
            $text = $node['text'] ?? '';
            if ($text === $anchor && !empty($node['marks'] ?? null) && $wrapOffset === null) {
                $wrapOffset = $offset;
            }
            $offset += mb_strlen($text);
            $fullText .= $text;
        }
        $this->assertNotNull($wrapOffset, 'No wrapped anchor text-node found in paragraph');

        // Find all occurrence positions of $anchor in the concatenated text.
        $positions = [];
        $cursor = 0;
        $anchorLen = mb_strlen($anchor);
        while (($p = mb_stripos($fullText, $anchor, $cursor)) !== false) {
            $positions[] = $p;
            $cursor = $p + $anchorLen;
        }

        $this->assertGreaterThan($expectedOccurrenceIndex, count($positions),
            "Expected at least ".($expectedOccurrenceIndex + 1)." occurrences, found ".count($positions));
        $this->assertSame($positions[$expectedOccurrenceIndex], $wrapOffset,
            "Wrap landed at offset $wrapOffset; expected occurrence #$expectedOccurrenceIndex at offset {$positions[$expectedOccurrenceIndex]}");
    }

    /** True if any text node in $para has the literal anchor text AND a non-empty marks array. */
    private function paragraphHasWrappedAnchor(array $para, string $anchor): bool
    {
        foreach ($para['content'] ?? [] as $node) {
            if (($node['type'] ?? null) !== 'text') continue;
            $text = $node['text'] ?? '';
            // Use case-insensitive comparison: the walker is case-insensitive,
            // and the wrapped text-node may preserve the source casing
            // (e.g. "REDIS" when the anchor argument was "Redis").
            if (mb_strtolower($text) === mb_strtolower($anchor) && !empty($node['marks'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Markdown variant: assert that exactly one wrap of $anchor exists in
     * $out AND its byte position falls inside paragraph #$expectedParaIndex
     * (paragraphs are split on "\n\n" in the ORIGINAL markdown).
     */
    private function assertMarkdownWrappedAtParagraph(string $out, string $originalMd, int $expectedParaIndex, string $anchor): void
    {
        $linkPattern = '/\['.preg_quote($anchor, '/').'\]\('.preg_quote(self::HREF, '/').'\)/u';
        preg_match_all($linkPattern, $out, $matches, PREG_OFFSET_CAPTURE);
        $this->assertCount(1, $matches[0], 'Markdown: exactly one wrap expected');
        $wrappedByte = $matches[0][0][1];

        // Locate paragraph boundaries in the OUTPUT (boundaries shift only by
        // the inserted `[](url)` syntax; we can still use original-md offsets
        // as the prefix up to the wrap point matches).
        $paragraphs = explode("\n\n", $originalMd);
        $cursor = 0;
        $rangeStart = null;
        $rangeEnd = null;
        foreach ($paragraphs as $i => $p) {
            $start = $cursor;
            $end = $cursor + strlen($p); // byte length; markdown lives in bytes here
            if ($i === $expectedParaIndex) {
                $rangeStart = $start;
                $rangeEnd = $end + strlen('](' . self::HREF . ')') + 2; // be generous with end
                break;
            }
            $cursor = $end + strlen("\n\n");
        }
        $this->assertNotNull($rangeStart);
        $this->assertGreaterThanOrEqual($rangeStart, $wrappedByte, "Wrap must land in paragraph #$expectedParaIndex");
        $this->assertLessThan($rangeEnd, $wrappedByte, "Wrap must not extend beyond paragraph #$expectedParaIndex");
    }

    private function assertReplicatorWrappedInSet(array $repOut, int $expectedSetIndex, int $expectedParaIndex, string $anchor): void
    {
        $set = $repOut[$expectedSetIndex] ?? null;
        $this->assertNotNull($set, "Replicator set #$expectedSetIndex missing");
        $body = $set['body'] ?? [];
        $this->assertBardWrappedParagraph($body, $expectedParaIndex, $anchor);
    }

    private function assertReplicatorNotWrappedInSet(array $repOut, int $setIndex, string $anchor): void
    {
        $set = $repOut[$setIndex] ?? null;
        if ($set === null) return;
        $body = $set['body'] ?? [];
        foreach ($body as $i => $para) {
            $this->assertFalse(
                $this->paragraphHasWrappedAnchor($para, $anchor),
                "Replicator set #$setIndex paragraph #$i must NOT be wrapped"
            );
        }
    }
}
