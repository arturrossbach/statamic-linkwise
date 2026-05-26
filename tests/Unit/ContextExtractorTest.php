<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Support\ContextExtractor;
use PHPUnit\Framework\TestCase;

class ContextExtractorTest extends TestCase
{
    public function test_extracts_context_around_first_occurrence(): void
    {
        $text = 'When configuring your production server, you should follow the Redis Setup Guide for the best results.';
        $out = ContextExtractor::extract($text, 'Redis Setup Guide');
        $this->assertStringContainsString('Redis Setup Guide', $out);
        $this->assertStringContainsString('follow the', $out);
    }

    public function test_returns_empty_when_anchor_missing(): void
    {
        $out = ContextExtractor::extract('Some text without the term', 'Redis');
        $this->assertSame('', $out);
    }

    public function test_picks_specific_occurrence(): void
    {
        $text = 'Redis is great. We use Redis for caching. Redis is fast.';
        $first = ContextExtractor::extractStructured($text, 'Redis', 60, 0);
        $second = ContextExtractor::extractStructured($text, 'Redis', 60, 1);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first['text'], $second['text']);
    }

    /**
     * Bug-1 root cause: TextExtractor::fromBard joins paragraphs with "\n".
     * If extractContext blindly takes ±halfWindow chars, the resulting context
     * can include text from a neighbouring paragraph. When that string is
     * later passed back to BardLinkInserter as expectedSentenceContext, the
     * inserter cannot find it inside any single paragraph and silently rejects
     * the wrap. Mirrors SuggestionEngineTest invariant.
     */
    public function test_context_never_crosses_paragraph_boundary(): void
    {
        $text = "Heading line\nFirst sentence containing AnchorWord here. Another sentence follows.";
        $out = ContextExtractor::extract($text, 'AnchorWord');
        $this->assertStringContainsString('AnchorWord', $out);
        $this->assertStringNotContainsString("\n", $out, "Context must not span paragraph boundary, got: $out");
    }

    public function test_context_at_paragraph_end_does_not_bleed_into_next(): void
    {
        $text = "A long single-paragraph sentence ending with AnchorWord\nNext paragraph starts here with more words.";
        $out = ContextExtractor::extract($text, 'AnchorWord');
        $this->assertStringContainsString('AnchorWord', $out);
        $this->assertStringNotContainsString("\n", $out);
        $this->assertStringNotContainsString('Next paragraph', $out);
    }

    public function test_context_at_paragraph_start_does_not_bleed_into_previous(): void
    {
        $text = "Previous paragraph with many words leading to nothing in particular here\nAnchorWord starts the next one.";
        $out = ContextExtractor::extract($text, 'AnchorWord');
        $this->assertStringContainsString('AnchorWord', $out);
        $this->assertStringNotContainsString("\n", $out);
        $this->assertStringNotContainsString('Previous paragraph', $out);
    }

    /**
     * Bug 2026-05-26 (User-Smoke V1.2-J): URL Changer showed context
     * "getestete Ausrüstung" — just the anchor — when the actual paragraph
     * was "Vorgestellt: getestete Ausrüstung". Root cause: extractAtOffset's
     * snap-to-word block ALWAYS advanced $start to the next space if any
     * space existed before $offset. After clampToParagraph anchored $start
     * on a "\n"+1 paragraph boundary (so $start was already at a word
     * beginning), the snap discarded the entire pre-anchor lead-in,
     * collapsing the snippet to just the anchor.
     *
     * Fix: snap only when $start lands mid-word (char immediately before is
     * non-whitespace). This pin documents the intended invariant.
     */
    public function test_paragraph_lead_in_before_anchor_is_preserved(): void
    {
        // Anchor sits at position 14 ("getestete Ausrüstung"); paragraph
        // starts with "Vorgestellt: " (12 chars + space). Without the snap
        // fix, $start would advance from 0 → 13 (after first space) and the
        // snippet would lose "Vorgestellt:".
        $text = "Tagestour: 227 km.\nVorgestellt: getestete Ausrüstung\nDinge zu benennen ist schwer.";
        $offset = mb_strpos($text, 'getestete Ausrüstung');
        $this->assertIsInt($offset);

        $result = ContextExtractor::extractAtOffset($text, $offset, mb_strlen('getestete Ausrüstung'));

        $this->assertNotNull($result);
        $this->assertSame('Vorgestellt: getestete Ausrüstung', $result['text']);
    }

    /**
     * Companion pin: confirm snap STILL fires when $start genuinely lands
     * mid-word (e.g. half-window extends into a previous word). Otherwise
     * we'd over-extend snippets backwards into truncated fragments.
     */
    public function test_snap_still_fires_when_start_lands_mid_word(): void
    {
        // Single long paragraph, no "\n"; half-window will land $start
        // somewhere mid-word in the very long preamble. Snap should
        // advance to the next space.
        $preamble = str_repeat('LongPreambleWordsThatExceedHalfWindow ', 5);
        $text = $preamble.'finally AnchorTarget appears here.';
        $offset = mb_strpos($text, 'AnchorTarget');
        $this->assertIsInt($offset);

        $result = ContextExtractor::extractAtOffset($text, $offset, mb_strlen('AnchorTarget'), 60);

        $this->assertNotNull($result);
        // Must not start mid-word — first char of result is either a known
        // word boundary or a complete word.
        $first = mb_substr($result['text'], 0, 1);
        $this->assertNotSame(' ', $first, 'Result must not start with a space.');
        // Must contain the anchor.
        $this->assertStringContainsString('AnchorTarget', $result['text']);
        // The snippet must not be a partial mid-word like "WindowFinally" —
        // first word must exist in the original text as a standalone token.
        $firstWord = preg_split('/\s+/', $result['text'])[0];
        $this->assertMatchesRegularExpression('/(^|\s)'.preg_quote($firstWord, '/').'($|\s)/u', $text);
    }
}
