<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Indexer\EntryIndexer;
use Arturrossbach\Linkwise\Tests\TestCase;
use Mockery;
use Statamic\Entries\Entry;

/**
 * Pin-set for the indexer/writer field-symmetry contract and the inbound
 * dry-run filter argument parity.
 *
 * The premise: any field type the link-INSERTER cannot write to has no
 * business being read by the link-INDEXER either. Whatever text the
 * indexer reads from such a field surfaces as anchor candidates that
 * can never be applied — phantom suggestions that fail at click-time
 * with "anchor text not found in entry content".
 *
 * Three subsystems share this contract and are pinned here:
 *
 *  1. Top-level fields: writer touches bard/replicator/markdown only
 *     (see BardLinkInserter::insertLinkIntoEntryWithHref lines 341-380).
 *     Indexer historically also indexed `text` + `textarea` plaintext,
 *     producing phantoms.
 *
 *  2. Replicator-nested plain strings: writer skips them explicitly
 *     (see ReplicatorLinkRouter::processReplicatorWithHref lines 160-170:
 *     "writing `[anchor](url)` into a plaintext template surfaces as
 *     visible literal syntax"). Indexer's `extractBardFromReplicator`
 *     historically collected them into `$plainTexts`, again producing
 *     phantoms.
 *
 *  3. Dry-run filter in InboundEngine::suggestFiltered drops the
 *     `sentence_context` argument when calling the dry-run inserter,
 *     while the real-write path (LinkInsertCommand:198-211) passes it.
 *     Result: dry-run accepts suggestions whose sentence_context is in
 *     a non-writable region, real-write rejects them at apply-time.
 */
class IndexerWriterSymmetryAndFilterParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Top-level field-type symmetry ──────────────────────────────────

    public function test_it_excludes_top_level_text_textarea_from_record_text(): void
    {
        // Blueprint: subtitle (text), summary (textarea), body (bard).
        // Each value contains a unique marker so we can detect which
        // got read into $record->text. After fix: only the bard marker
        // shows up; the text/textarea markers must NOT.
        // Markers chosen WITHOUT underscores/asterisks — the indexer
        // strips `[#*_~`>]` as a markdown clean-up step, which would
        // otherwise mangle our markers and let the assertion pass for
        // the wrong reason.
        $entry = $this->entryWithFields('e1', 'My Title', [
            'subtitle' => ['type' => 'text', 'value' => 'markertextfieldkeepout'],
            'summary' => ['type' => 'textarea', 'value' => 'markertextareafieldkeepout'],
            'body' => ['type' => 'bard', 'value' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'markerbardfieldkeepin']],
            ]]],
        ]);

        $indexer = new EntryIndexer(sys_get_temp_dir().'/linkwise-test-'.uniqid());
        $record = $indexer->indexEntry($entry);

        $this->assertNotNull($record);
        $this->assertStringContainsString('markerbardfieldkeepin', $record->text,
            'Bard content must remain indexed');
        $this->assertStringNotContainsString('markertextfieldkeepout', $record->text,
            'Plain `text` field must NOT be indexed (writer cannot reach it)');
        $this->assertStringNotContainsString('markertextareafieldkeepout', $record->text,
            'Plain `textarea` field must NOT be indexed (writer cannot reach it)');
    }

    public function test_it_excludes_replicator_nested_plain_strings_from_record_text(): void
    {
        // Blueprint has one `content` replicator field. The set carries
        // both a plain-string subfield ("heading") and a Bard subfield
        // ("body"). Each with a unique marker.
        // Underscore-free markers — see test_it_excludes_top_level for
        // why (markdown clean-up regex strips underscores).
        $replicatorValue = [[
            'id' => 'set-1',
            'type' => 'card',
            'heading' => 'markernestedtextkeepout',
            'body' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'markernestedbardkeepin'],
                ]],
            ],
        ]];

        $entry = $this->entryWithFields('e2', 'Card Page', [
            'content' => ['type' => 'replicator', 'value' => $replicatorValue],
        ]);

        $indexer = new EntryIndexer(sys_get_temp_dir().'/linkwise-test-'.uniqid());
        $record = $indexer->indexEntry($entry);

        $this->assertNotNull($record);
        $this->assertStringContainsString('markernestedbardkeepin', $record->text,
            'Bard fragment nested in a replicator set must remain indexed');
        $this->assertStringNotContainsString('markernestedtextkeepout', $record->text,
            'Plain-string nested in a replicator set must NOT be indexed (writer skips it)');
    }

    // ── AutoLink pre-check downstream symmetry (Test 7) ────────────────

    public function test_it_does_not_attempt_apply_when_keyword_only_in_text_field(): void
    {
        // The AutoLink pre-check `textContainsKeywordAtBoundary` consults
        // $record->text. If a keyword lives only in a non-writable
        // `text`/`textarea` field, the pre-check must return false (post-
        // fix) — otherwise AutoLink attempts a Bard-walk for an anchor
        // that lives in an unreachable region and fails at apply time.
        $entry = $this->entryWithFields('e3', 'Page', [
            'subtitle' => ['type' => 'text', 'value' => 'Only Anchor Lives Here word'],
            'body' => ['type' => 'bard', 'value' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Unrelated body content.']],
            ]]],
        ]);

        $indexer = new EntryIndexer(sys_get_temp_dir().'/linkwise-test-'.uniqid());
        $record = $indexer->indexEntry($entry);

        $this->assertNotNull($record);
        // Post-fix: the unique keyword "Only Anchor Lives Here word" lives
        // only in the `text` subtitle; $record->text must not contain it,
        // so any downstream substring search will miss — AutoLink stops
        // before attempting a doomed Bard walk.
        $this->assertStringNotContainsString('Only Anchor Lives Here word', $record->text,
            'Keyword only in `text` field must not appear in $record->text — '
            .'otherwise AutoLink pre-check passes and apply fails downstream.');
    }

    // ── Filter argument parity (Tests 3, 4, 5) ─────────────────────────
    //
    // These three tests pin the **call shape** of
    // `InboundEngine::suggestFiltered` rather than its end-to-end
    // behaviour. Rationale: the dry-run inserter's behaviour with the
    // `$expectedSentenceContext` argument is already exhaustively pinned
    // in `MutatorAndInsertParityTest` (multi-line context, twice-anchor,
    // markdown-link inside sentence, null/empty context all covered).
    // What's NOT pinned today is whether `InboundEngine` actually PASSES
    // the argument — i.e. argument parity with the real-write path in
    // `LinkInsertCommand`. A static behavioural test would require
    // Statamic facade interception which the unit-test harness doesn't
    // support (Statamic\Facades\Entry uses a custom resolver). Source-
    // level pin is the contract we actually fix.

    public function test_filter_rejects_suggestion_whose_sentence_context_is_unreachable_for_writer(): void
    {
        // Contract: when filter calls the dry-run inserter, it must pass
        // `$s->sentenceContext` as the 6th positional argument — matching
        // `LinkInsertCommand::execute()` real-write call (lines 198-211)
        // and `BardLinkInserter::insertLinkIntoEntryWithHref` signature
        // (line 324). Without parity, dry-run accepts suggestions whose
        // sentence-context lives in a non-writable field (subtitle/
        // textarea) and the user-facing "Apply" silently fails.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Suggestions/InboundEngine.php');
        $this->assertMatchesRegularExpression(
            '/insertLinkIntoEntryWithHref\s*\(\s*\$s->sourceEntryId\s*,\s*\$s->anchorText\s*,\s*\$href\s*,\s*false\s*,\s*false\s*,\s*\$s->sentenceContext\s*\)/s',
            $src,
            'InboundEngine::suggestFiltered must call insertLinkIntoEntryWithHref with '
            .'$s->sentenceContext as the 6th argument — matching LinkInsertCommand real-write parity.',
        );
    }

    public function test_filter_keeps_suggestion_whose_sentence_context_is_reachable(): void
    {
        // Regression pin against over-narrowing. The inserter's behaviour
        // when given a reachable sentence_context is covered by
        // MutatorAndInsertParityTest::test_insert_parity_context_with_newline_*
        // and BardLinkInserterTest's anchor-finding suite. Here we pin
        // the OTHER half of the parity contract: the call site must use
        // `$s->sentenceContext` literally — not `$s->sentenceContext ?: null`
        // nor any transformation that would re-introduce the asymmetry.
        $src = file_get_contents(dirname(__DIR__, 2).'/src/Suggestions/InboundEngine.php');
        $this->assertStringContainsString(
            '$s->sentenceContext',
            $src,
            'InboundEngine must reference $s->sentenceContext at the dry-run call site.',
        );
        // And: there must be no transformation between the suggestion
        // field and the inserter call — straight pass-through.
        $this->assertDoesNotMatchRegularExpression(
            '/insertLinkIntoEntryWithHref\s*\([^)]*\$s->sentenceContext\s*\?[?:]/s',
            $src,
            'sentenceContext must be passed literally — no ?: / ?? transformation that '
            .'would re-introduce the dry-run vs. real-write asymmetry.',
        );
    }

    public function test_filter_keeps_suggestion_without_sentence_context_legacy_null_safety(): void
    {
        // Pin: the inserter's signature accepts `?string` for the
        // sentence-context argument (BardLinkInserter:324) — legacy
        // null safety is at the SIGNATURE level, not the call site.
        // Any future refactor that tightens the signature to `string`
        // would break legacy suggestions whose sentence-context field
        // was migrated to '' (empty string) from older shapes.
        $bardSrc = file_get_contents(dirname(__DIR__, 2).'/src/Support/BardLinkInserter.php');
        $this->assertMatchesRegularExpression(
            '/public static function insertLinkIntoEntryWithHref\([^)]*\?string\s+\$expectedSentenceContext\s*=\s*null/s',
            $bardSrc,
            'BardLinkInserter::insertLinkIntoEntryWithHref must keep `?string $expectedSentenceContext = null` '
            .'so legacy suggestions with empty/missing sentence_context still pass cleanly.',
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Build an Entry mock with arbitrary handle => [type, value] fields.
     * The blueprint reports each field's type; $entry->get($handle) and
     * $entry->value($handle) both return the configured value.
     */
    private function entryWithFields(string $id, string $title, array $fields): Entry
    {
        $fieldMocks = [];
        foreach ($fields as $handle => $spec) {
            $f = Mockery::mock();
            $f->shouldReceive('type')->andReturn($spec['type']);
            $fieldMocks[$handle] = $f;
        }

        $fieldsCollection = Mockery::mock();
        $fieldsCollection->shouldReceive('all')->andReturn($fieldMocks);

        $blueprint = Mockery::mock();
        $blueprint->shouldReceive('fields')->andReturn($fieldsCollection);

        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn($id);
        $entry->shouldReceive('title')->andReturn($title);
        $entry->shouldReceive('blueprint')->andReturn($blueprint);
        $entry->shouldReceive('url')->andReturn('/'.$id);
        $entry->shouldReceive('collectionHandle')->andReturn('pages');
        foreach ($fields as $handle => $spec) {
            $entry->shouldReceive('get')->with($handle)->andReturn($spec['value']);
            $entry->shouldReceive('value')->with($handle)->andReturn($spec['value']);
        }
        // title getter
        $entry->shouldReceive('get')->with('title')->andReturn($title);

        return $entry;
    }

}
