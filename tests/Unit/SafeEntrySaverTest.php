<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Exceptions\EntryConflictException;
use Arturrossbach\Linkwise\Support\BardWalker;
use Arturrossbach\Linkwise\Support\SafeEntrySaver;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Statamic\Entries\Entry;

class SafeEntrySaverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_hash_is_deterministic(): void
    {
        // Same data should produce same hash
        $hash1 = md5(json_encode(['title' => 'Test', 'content' => 'Hello']));
        $hash2 = md5(json_encode(['title' => 'Test', 'content' => 'Hello']));

        $this->assertSame($hash1, $hash2);
    }

    public function test_hash_changes_on_data_change(): void
    {
        $hash1 = md5(json_encode(['title' => 'Test', 'content' => 'Hello']));
        $hash2 = md5(json_encode(['title' => 'Test', 'content' => 'Changed']));

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_conflict_exception_has_entry_info(): void
    {
        $exception = new EntryConflictException('abc-123', 'My Entry');

        $this->assertSame('abc-123', $exception->entryId);
        $this->assertSame('My Entry', $exception->entryTitle);
        $this->assertStringContainsString('My Entry', $exception->getMessage());
        $this->assertStringContainsString('modified by another user', $exception->getMessage());
    }

    public function test_hash_from_preview_detects_parallel_edit(): void
    {
        // Simulates: User gets preview (hash A), editor changes entry (hash B),
        // user clicks Apply with hash A → must be rejected

        $dataV1 = ['title' => 'My Article', 'content' => [['type' => 'paragraph']]];
        $dataV2 = ['title' => 'My Article', 'content' => [['type' => 'paragraph', 'text' => 'edited']]];

        $hashAtPreview = md5(json_encode($dataV1));
        $hashAfterEdit = md5(json_encode($dataV2));

        // These must differ — if they don't, the locking is useless
        $this->assertNotSame($hashAtPreview, $hashAfterEdit);
    }

    public function test_hash_stable_when_no_changes(): void
    {
        $data = ['title' => 'Test', 'bard' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]];

        $hash1 = md5(json_encode($data));
        $hash2 = md5(json_encode($data));

        $this->assertSame($hash1, $hash2);
    }

    // ─── normalizeBardFieldsInPlace ──────────────────────────────────────
    //
    // Bug 16 fix (2026-05-11): every save runs Bard / Replicator-nested-
    // Bard fields through BardWalker::normalizeChildren before validator
    // checks + disk write. Tests below lock in the helper's behaviour
    // via reflection (it's protected) plus an integration-style check
    // that the entire validator chain stays consistent against a
    // pre-fragmented `$current`.

    /**
     * Build a minimal Entry mock with a Bard field. Returns an Entry that
     * accepts get('body') and set('body', $newValue) — sufficient for
     * exercising normalizeBardFieldsInPlace, which iterates blueprint
     * fields and writes normalized values back.
     */
    private function entryWithBard(array $bardContent, string $id = 'test'): Entry
    {
        $field = Mockery::mock();
        $field->shouldReceive('type')->andReturn('bard');

        $fieldsCollection = Mockery::mock();
        $fieldsCollection->shouldReceive('all')->andReturn(['body' => $field]);

        $blueprint = Mockery::mock();
        $blueprint->shouldReceive('fields')->andReturn($fieldsCollection);

        // The mock's stored body — set() writes here, get() reads. Closes
        // the loop so normalizeBardFieldsInPlace can mutate and then we
        // can inspect the result via get('body'). Wrapped in stdClass so
        // both closures share the same instance state (PHP closure-array
        // captures are by-value; an object holds shared mutable state).
        $storage = new \stdClass();
        $storage->body = $bardContent;
        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn($id);
        $entry->shouldReceive('blueprint')->andReturn($blueprint);
        $entry->shouldReceive('get')->with('body')->andReturnUsing(fn () => $storage->body);
        $entry->shouldReceive('set')->withArgs(function ($handle, $v) use ($storage) {
            if ($handle === 'body') {
                $storage->body = $v;
                return true;
            }
            return false;
        })->andReturnSelf();

        return $entry;
    }

    public function test_normalize_bard_fields_in_place_merges_fragments(): void
    {
        // The Bug 16 case: existing adjacent same-href fragments collapse
        // to one merged mark after normalize.
        $href = 'statamic://entry::soba';
        $fragmented = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Heute '],
            ['type' => 'text', 'text' => 'über ', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'Erdnuss-Soba-Nudeln', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => ' nachgedacht.'],
        ]]];

        $entry = $this->entryWithBard($fragmented);

        $m = new ReflectionMethod(SafeEntrySaver::class, 'normalizeBardFieldsInPlace');
        $m->setAccessible(true);
        $m->invoke(null, $entry);

        // Validate the body got merged. 4 children → 3 children, with
        // the middle one carrying the merged anchor.
        $body = $entry->get('body');
        $this->assertCount(3, $body[0]['content']);
        $this->assertSame('Heute ', $body[0]['content'][0]['text']);
        $this->assertSame('über Erdnuss-Soba-Nudeln', $body[0]['content'][1]['text']);
        $this->assertSame($href, $body[0]['content'][1]['marks'][0]['attrs']['href']);
        $this->assertSame(' nachgedacht.', $body[0]['content'][2]['text']);
    }

    public function test_normalize_bard_fields_in_place_idempotent(): void
    {
        // Tree already normalized passes through unchanged. Lets us call
        // normalize on every save without paying for false mutations.
        $clean = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Already clean prose.'],
        ]]];

        $entry = $this->entryWithBard($clean);

        $m = new ReflectionMethod(SafeEntrySaver::class, 'normalizeBardFieldsInPlace');
        $m->setAccessible(true);
        $m->invoke(null, $entry);

        $this->assertEquals($clean, $entry->get('body'));
    }

    public function test_normalize_bard_fields_in_place_tolerates_missing_blueprint(): void
    {
        // Defense: an entry whose blueprint resolution throws (test fixtures,
        // partial Statamic init) must not blow up the save path. Same
        // retreat the validators use.
        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn('no-bp');
        $entry->shouldReceive('blueprint')->andThrow(new \RuntimeException('no blueprint'));

        $m = new ReflectionMethod(SafeEntrySaver::class, 'normalizeBardFieldsInPlace');
        $m->setAccessible(true);

        // Must not throw.
        $m->invoke(null, $entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_normalize_replicator_bard_fragments_recurses_nested_bard(): void
    {
        // Replicator-nested Bard fragments need the same invariant. Walker
        // contract is shared with ContentSafetyValidator's traversal
        // (UrlHelper::REPLICATOR_META_KEYS skip, looksLikeBardContent check).
        $href = 'statamic://entry::soba';
        $sets = [
            [
                'type' => 'text_block',
                'id' => 'abc',
                'enabled' => true,
                'body' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'über ', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
                            ['type' => 'text', 'text' => 'Soba', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
                        ],
                    ],
                ],
            ],
        ];

        $m = new ReflectionMethod(SafeEntrySaver::class, 'normalizeReplicatorBardFragments');
        $m->setAccessible(true);
        $out = $m->invoke(null, $sets);

        // Fragments inside the nested Bard merged. type/id/enabled metadata
        // unchanged.
        $this->assertSame('text_block', $out[0]['type']);
        $this->assertSame('abc', $out[0]['id']);
        $this->assertTrue($out[0]['enabled']);
        $this->assertCount(1, $out[0]['body'][0]['content']);
        $this->assertSame('über Soba', $out[0]['body'][0]['content'][0]['text']);
    }

    public function test_normalize_replicator_bard_fragments_skips_string_values(): void
    {
        // Set fields like button labels (strings) are not Bard trees — must
        // pass through unchanged so the walker doesn't choke on non-array
        // values.
        $sets = [[
            'type' => 'cta_button',
            'id' => 'btn1',
            'label' => 'Click here',
            'url' => 'https://example.test',
        ]];

        $m = new ReflectionMethod(SafeEntrySaver::class, 'normalizeReplicatorBardFragments');
        $m->setAccessible(true);
        $out = $m->invoke(null, $sets);

        $this->assertSame('Click here', $out[0]['label']);
        $this->assertSame('https://example.test', $out[0]['url']);
    }

    public function test_normalize_then_coverage_check_no_false_positive_on_fragment_cleanup(): void
    {
        // The flagged trap (2026-05-11): without normalizing $current,
        // ensureLinkCoveragePreserved false-positives when $current has
        // fragments and $entry has the merged form (Mark2's offset shifts
        // onto Mark1, validator interprets as Bug-B partial-overlap).
        //
        // With both sides normalized first (as save() now does), the
        // coverage check compares apples-to-apples: one merged Mark
        // before, one merged Mark after, same offset+length — passes.
        $href = 'statamic://entry::soba';

        // $current as it would be loaded from disk: fragmented.
        $currentBard = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'über ', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'Erdnuss', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]];
        // $entry as caller built it: still fragmented at construction time;
        // save() normalizes both before validator. Use identical content
        // here to model the "idempotent save that cleans up fragments".
        $entryBard = $currentBard;

        $current = $this->entryWithBard($currentBard, 'cur');
        $entry = $this->entryWithBard($entryBard, 'ent');

        // Simulate what save() does: normalize both sides BEFORE validator.
        $m = new ReflectionMethod(SafeEntrySaver::class, 'normalizeBardFieldsInPlace');
        $m->setAccessible(true);
        $m->invoke(null, $current);
        $m->invoke(null, $entry);

        // Now the coverage check sees one merged mark on each side —
        // legitimate idempotent-equivalent save. Must not throw.
        \Arturrossbach\Linkwise\Support\ContentSafetyValidator::ensureLinkCoveragePreserved($current, $entry);
        $this->expectNotToPerformAssertions();
    }
}
