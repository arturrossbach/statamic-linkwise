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
}
