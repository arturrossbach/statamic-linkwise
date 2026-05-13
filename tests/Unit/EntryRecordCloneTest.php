<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryRecord;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for EntryRecord clone-semantics.
 *
 * Why this file: the codebase has 5 "construct a copy with overrides" sites
 * (EntryIndexer × 4, EntryIndexSubscriber × 1) plus 2 dedicated
 * withInboundSuggestionCount / withOutboundSuggestionCount methods. Every
 * new EntryRecord field has to be added to all 7 places by hand. This has
 * already caused 2 production bugs (inbound-count drift after
 * outboundLinkOccurrences was added; suggestion-count drift in an earlier
 * sprint).
 *
 * REV-DR-03 introduces a generic EntryRecord::with(array $overrides) so the
 * Override-Sites collapse to a single line. THESE tests pin the existing
 * with* semantics down so the refactor is provably regression-free: each
 * test names every preserved field explicitly, so a future EntryRecord
 * field that's silently dropped will fail here, not in production.
 *
 * @see docs/ARCHITECTURE_REVIEW.md REV-DR-03
 * @see architectural_health.md Klasse 4
 */
class EntryRecordCloneTest extends TestCase
{
    /** Fully-populated record used as the "preserve everything except X" baseline. */
    private function fullyPopulatedRecord(): EntryRecord
    {
        return new EntryRecord(
            id: 'entry-abc',
            title: 'Title with Umlaut äöü',
            url: '/blog/entry-abc',
            collection: 'articles',
            text: 'Body text with multiple sentences. Second sentence.',
            outboundLinks: ['target-1', 'target-2'],
            keywords: ['statamic' => 0.42, 'linking' => 0.31],
            inboundSuggestionCount: 7,
            outboundSuggestionCount: 13,
            hasTitleMatch: true,
            tokens: ['title', 'umlaut', 'body', 'text'],
            outboundLinkOccurrences: ['target-1', 'target-1', 'target-2'],
        );
    }

    /**
     * Locks down the EXISTING withInboundSuggestionCount behavior. Every one
     * of the 12 fields must be carried through unchanged except for
     * inboundSuggestionCount itself. If a future change drops a field
     * silently, this test fails BEFORE production sees stale data.
     */
    public function test_withInboundSuggestionCount_preserves_all_other_fields(): void
    {
        $base = $this->fullyPopulatedRecord();
        $copy = $base->withInboundSuggestionCount(42);

        $this->assertSame(42, $copy->inboundSuggestionCount, 'inboundSuggestionCount must be overridden');

        // Every other field must be byte-identical.
        $this->assertSame($base->id, $copy->id);
        $this->assertSame($base->title, $copy->title);
        $this->assertSame($base->url, $copy->url);
        $this->assertSame($base->collection, $copy->collection);
        $this->assertSame($base->text, $copy->text);
        $this->assertSame($base->outboundLinks, $copy->outboundLinks);
        $this->assertSame($base->keywords, $copy->keywords);
        $this->assertSame($base->outboundSuggestionCount, $copy->outboundSuggestionCount);
        $this->assertSame($base->hasTitleMatch, $copy->hasTitleMatch);
        $this->assertSame($base->tokens, $copy->tokens);
        $this->assertSame($base->outboundLinkOccurrences, $copy->outboundLinkOccurrences);
    }

    /** Locks down the EXISTING withOutboundSuggestionCount behavior. */
    public function test_withOutboundSuggestionCount_preserves_all_other_fields(): void
    {
        $base = $this->fullyPopulatedRecord();
        $copy = $base->withOutboundSuggestionCount(99);

        $this->assertSame(99, $copy->outboundSuggestionCount);

        $this->assertSame($base->id, $copy->id);
        $this->assertSame($base->title, $copy->title);
        $this->assertSame($base->url, $copy->url);
        $this->assertSame($base->collection, $copy->collection);
        $this->assertSame($base->text, $copy->text);
        $this->assertSame($base->outboundLinks, $copy->outboundLinks);
        $this->assertSame($base->keywords, $copy->keywords);
        $this->assertSame($base->inboundSuggestionCount, $copy->inboundSuggestionCount);
        $this->assertSame($base->hasTitleMatch, $copy->hasTitleMatch);
        $this->assertSame($base->tokens, $copy->tokens);
        $this->assertSame($base->outboundLinkOccurrences, $copy->outboundLinkOccurrences);
    }

    /** Chained with* calls preserve intermediate state. */
    public function test_chained_withCount_methods_preserve_intermediate_state(): void
    {
        $base = $this->fullyPopulatedRecord();
        $copy = $base
            ->withInboundSuggestionCount(11)
            ->withOutboundSuggestionCount(22);

        $this->assertSame(11, $copy->inboundSuggestionCount);
        $this->assertSame(22, $copy->outboundSuggestionCount);
        $this->assertSame($base->id, $copy->id);
        $this->assertSame($base->outboundLinkOccurrences, $copy->outboundLinkOccurrences);
    }

    /**
     * Override-pattern in production: "build a new record from $record by
     * changing exactly one field" — the manual constructor-call pattern
     * used in EntryIndexer + EntryIndexSubscriber. Locks down that copying
     * EVERY field by hand produces an equal record. Post-REV-DR-03 this is
     * the contract `with()` must reproduce.
     */
    public function test_manual_full_field_copy_equals_original(): void
    {
        $base = $this->fullyPopulatedRecord();

        $manualCopy = new EntryRecord(
            id: $base->id,
            title: $base->title,
            url: $base->url,
            collection: $base->collection,
            text: $base->text,
            outboundLinks: $base->outboundLinks,
            keywords: $base->keywords,
            inboundSuggestionCount: $base->inboundSuggestionCount,
            outboundSuggestionCount: $base->outboundSuggestionCount,
            hasTitleMatch: $base->hasTitleMatch,
            tokens: $base->tokens,
            outboundLinkOccurrences: $base->outboundLinkOccurrences,
        );

        // toArray() round-trip is the strongest "everything is preserved" signal
        // because it touches every public field.
        $this->assertSame($base->toArray(), $manualCopy->toArray());
    }

    /**
     * Override-pattern with field-change: locks down the contract that the
     * 5 production override-sites rely on — copy every field by hand,
     * substitute one. Names the substituted field explicitly so the
     * with()-refactor can prove it produces identical output.
     */
    public function test_manual_full_field_copy_with_inbound_override_matches_dedicated_method(): void
    {
        $base = $this->fullyPopulatedRecord();

        // The pattern used in EntryIndexer::preserveSuggestionCounts (the
        // 5 override sites all follow this shape).
        $manualOverride = new EntryRecord(
            id: $base->id,
            title: $base->title,
            url: $base->url,
            collection: $base->collection,
            text: $base->text,
            outboundLinks: $base->outboundLinks,
            keywords: $base->keywords,
            inboundSuggestionCount: 555,
            outboundSuggestionCount: $base->outboundSuggestionCount,
            hasTitleMatch: $base->hasTitleMatch,
            tokens: $base->tokens,
            outboundLinkOccurrences: $base->outboundLinkOccurrences,
        );

        $dedicatedOverride = $base->withInboundSuggestionCount(555);

        $this->assertSame($manualOverride->toArray(), $dedicatedOverride->toArray(),
            'Manual full-field-copy + override must produce same result as withInboundSuggestionCount');
    }
}
