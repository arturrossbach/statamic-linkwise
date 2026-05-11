<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Exceptions\ContentCorruptionException;
use Arturrossbach\Linkwise\Support\ContentSafetyValidator;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Statamic\Entries\Entry;

/**
 * Last-line-of-defense tests. Each one represents a corruption pattern
 * we'd rather throw on than save. False positives would block legitimate
 * content; false negatives would let corrupt content reach disk. The
 * tests cover both directions.
 */
class ContentSafetyValidatorTest extends TestCase
{
    /**
     * Helper: invoke the private collectFromMarkdown directly. Builds a
     * violations array, then throws the first violation as a
     * ContentCorruptionException so the test's expectException works.
     * Returns silently when no violation is found.
     */
    private function validateMd(string $markdown): void
    {
        $violations = [];
        $m = new ReflectionMethod(ContentSafetyValidator::class, 'collectFromMarkdown');
        $m->setAccessible(true);
        $m->invokeArgs(null, ['body', $markdown, &$violations]);

        if (! empty($violations)) {
            $first = $violations[0];
            throw new \Arturrossbach\Linkwise\Exceptions\ContentCorruptionException(
                'test-entry', $first['field'], $first['reason'], $first['excerpt']
            );
        }
    }

    /** Same shape, but for the Bard tree walker. */
    private function validateBard(array $content): void
    {
        $violations = [];
        $m = new ReflectionMethod(ContentSafetyValidator::class, 'collectFromBardTree');
        $m->setAccessible(true);
        $m->invokeArgs(null, ['body', $content, &$violations]);

        if (! empty($violations)) {
            $first = $violations[0];
            throw new \Arturrossbach\Linkwise\Exceptions\ContentCorruptionException(
                'test-entry', $first['field'], $first['reason'], $first['excerpt']
            );
        }
    }

    // ─── Markdown invariant: nested-anchor closing `]](` ─────────────

    public function test_markdown_rejects_nested_anchor_pattern(): void
    {
        // Today's catastrophe: `[outer [inner](url)](url)`. The non-greedy
        // regex captures `outer [inner` as the FIRST match's anchor —
        // contains an unmatched `[`, which is the corruption signature.
        $corrupt = 'See [Modern web [development](statamic://entry::abc)](statamic://entry::xyz) for details.';

        $this->expectException(ContentCorruptionException::class);
        $this->expectExceptionMessageMatches('/anchor contains an unmatched/');

        $this->validateMd($corrupt);
    }

    // ─── Markdown invariant: nested link in URL portion ──────────────

    public function test_markdown_rejects_link_inside_url(): void
    {
        // Today's other catastrophe: keyword "statamic" matched inside
        // the URL `statamic://entry::xyz`, producing
        // `[anchor]([statamic](https://other)://entry::xyz)`. The URL
        // portion now contains another `](` substring.
        $corrupt = '[anchor]([statamic](https://other.com)://entry::xyz)';

        $this->expectException(ContentCorruptionException::class);
        $this->expectExceptionMessageMatches('/URL portion.*another `\]\(`/');

        $this->validateMd($corrupt);
    }

    // ─── Markdown invariant: legitimate content passes ───────────────

    public function test_markdown_accepts_normal_link(): void
    {
        $this->validateMd('See [Laravel docs](https://laravel.com) for details.');
        $this->expectNotToPerformAssertions();
    }

    public function test_markdown_accepts_multiple_separate_links(): void
    {
        $md = 'Read [Laravel](https://laravel.com), then [Vue](https://vuejs.org), then ship.';
        $this->validateMd($md);
        $this->expectNotToPerformAssertions();
    }

    public function test_markdown_accepts_brackets_in_prose(): void
    {
        // Stylistic brackets in prose must NOT trigger a false positive.
        // Plain `[See: example]` text without any `](` is harmless.
        $this->validateMd('Many systems use [bracket notation] for grouping.');
        $this->expectNotToPerformAssertions();
    }

    public function test_markdown_accepts_parens_in_prose(): void
    {
        $this->validateMd('Visit Laravel.com (or laravel.com/docs) for setup.');
        $this->expectNotToPerformAssertions();
    }

    public function test_markdown_accepts_complex_url(): void
    {
        // Real URLs with query params, anchors, encoded chars should pass.
        $md = '[Search](https://example.com/search?q=foo&bar=baz%20test#section)';
        $this->validateMd($md);
        $this->expectNotToPerformAssertions();
    }

    // ─── Bard invariant: empty href ──────────────────────────────────

    public function test_bard_rejects_empty_href(): void
    {
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Click here', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => '']],
                ]],
            ],
        ]];

        $this->expectException(ContentCorruptionException::class);
        $this->expectExceptionMessageMatches('/empty href/');
        $this->validateBard($bard);
    }

    // ─── Bard invariant: malformed href with brackets ────────────────

    public function test_bard_rejects_href_with_brackets(): void
    {
        // A href containing `[` or `]` means markdown syntax leaked in —
        // would render as `<a href="https://[bad].com">` which is broken.
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Bad link', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://[corrupt].com']],
                ]],
            ],
        ]];

        $this->expectException(ContentCorruptionException::class);
        $this->expectExceptionMessageMatches('/brackets or whitespace/');
        $this->validateBard($bard);
    }

    public function test_bard_rejects_href_with_whitespace(): void
    {
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Bad link', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://example .com/page']],
                ]],
            ],
        ]];

        $this->expectException(ContentCorruptionException::class);
        $this->validateBard($bard);
    }

    // ─── Bard invariant: link with empty visible text ────────────────

    public function test_bard_rejects_link_mark_on_empty_text(): void
    {
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => '', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://example.com']],
                ]],
            ],
        ]];

        $this->expectException(ContentCorruptionException::class);
        $this->expectExceptionMessageMatches('/empty visible text/');
        $this->validateBard($bard);
    }

    // ─── Bard invariant: legitimate Bard passes ──────────────────────

    public function test_bard_accepts_well_formed_link(): void
    {
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Visit '],
                ['type' => 'text', 'text' => 'Laravel', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://laravel.com']],
                ]],
                ['type' => 'text', 'text' => ' today.'],
            ],
        ]];
        $this->validateBard($bard);
        $this->expectNotToPerformAssertions();
    }

    public function test_bard_accepts_statamic_internal_link(): void
    {
        // `statamic://entry::uuid` is the internal link form Linkwise uses
        // — must pass the validator unchanged.
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Modern web development', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123-def']],
                ]],
            ],
        ]];
        $this->validateBard($bard);
        $this->expectNotToPerformAssertions();
    }

    public function test_bard_accepts_nested_paragraphs(): void
    {
        // Recursive walk must descend into all nested content arrays.
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Outer text'],
            ],
        ], [
            'type' => 'heading',
            'attrs' => ['level' => 2],
            'content' => [
                ['type' => 'text', 'text' => 'Section', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://example.com']],
                ]],
            ],
        ]];
        $this->validateBard($bard);
        $this->expectNotToPerformAssertions();
    }

    public function test_bard_accepts_text_without_marks(): void
    {
        // Plain text with no marks should pass without inspection.
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Just plain prose here.'],
            ],
        ]];
        $this->validateBard($bard);
        $this->expectNotToPerformAssertions();
    }

    public function test_bard_accepts_other_marks_alongside_no_link(): void
    {
        // Bold, italic, etc. marks on non-link nodes must not be inspected
        // for href/text rules.
        $bard = [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Bold text', 'marks' => [
                    ['type' => 'bold'],
                ]],
            ],
        ]];
        $this->validateBard($bard);
        $this->expectNotToPerformAssertions();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Collection mode: collectFromMarkdown / collectFromBardTree must NOT
    // bail on first violation. Multiple violations in the same content
    // must all surface — required for the diff-based validation in
    // SafeEntrySaver to count properly (1 pre-existing + 1 new = 2 must
    // be distinguishable from 1 pre-existing + 0 new = 1).
    // ─────────────────────────────────────────────────────────────────────

    public function test_markdown_collects_all_violations_not_just_first(): void
    {
        // Two distinct corruption sites in one markdown string. The old
        // throw-on-first behaviour would only see one — the new collect
        // path must surface both.
        $md = "First [a [b](c)](d) here. And second [e]([nested](u)://x). Done.";
        $violations = [];
        $m = new ReflectionMethod(ContentSafetyValidator::class, 'collectFromMarkdown');
        $m->setAccessible(true);
        $m->invokeArgs(null, ['body', $md, &$violations]);

        // Expect at least two violations across both corrupt segments —
        // the unmatched-bracket and the nested-URL pattern.
        $this->assertGreaterThanOrEqual(2, count($violations), 'multiple corruptions must be collected');

        $reasons = array_column($violations, 'reason');
        $this->assertTrue(
            collect($reasons)->contains(fn ($r) => str_contains($r, 'unmatched `[`')),
            'unmatched-bracket reason must appear',
        );
        $this->assertTrue(
            collect($reasons)->contains(fn ($r) => str_contains($r, 'another `](`')),
            'nested-URL reason must appear',
        );
    }

    public function test_count_by_key_groups_by_field_and_reason(): void
    {
        // Two violations with the same (field, reason) collapse to count=2;
        // a third with a different reason is its own bucket. This is what
        // ensureNoNewViolations uses to detect "save introduced new
        // corruption" — same key with higher count after vs before.
        $violations = [
            ['field' => 'body', 'reason' => 'reason-A', 'excerpt' => 'one'],
            ['field' => 'body', 'reason' => 'reason-A', 'excerpt' => 'two'],
            ['field' => 'body', 'reason' => 'reason-B', 'excerpt' => 'three'],
        ];
        $m = new ReflectionMethod(ContentSafetyValidator::class, 'countByKey');
        $m->setAccessible(true);
        $counts = $m->invoke(null, $violations);

        $this->assertSame(2, $counts['body::reason-A']);
        $this->assertSame(1, $counts['body::reason-B']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Link-coverage runtime gate (Bug B class — partial-overlap split).
    //
    // Domain rule: for each href in $before with N>0 linked chars, $after
    // must have either ≥N (preserved/extended) OR 0 (deliberate removal).
    // 0 < after < before is the "shortened-without-removal" pattern that
    // Bug B produced. SafeEntrySaver::save calls
    // ensureLinkCoveragePreserved as the last write-gate — fail-closed.
    //
    // The cases below cover every permutation of legitimate vs. corrupt
    // diff between two entry states. Tests use Mockery-mocked Entry stubs
    // because the real Statamic Entry's blueprint+fields chain is
    // expensive to construct in a unit test and orthogonal to the logic
    // under test.
    // ─────────────────────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build an Entry mock whose `body` field is a Bard tree. The Entry's
     * blueprint reports a single bard-typed field, so $entry->get('body')
     * returns the supplied content. Sufficient surface for the
     * ensureLinkCoveragePreserved walk.
     */
    private function entryWithBard(array $bardContent, string $id = 'test-entry'): Entry
    {
        $field = Mockery::mock();
        $field->shouldReceive('type')->andReturn('bard');

        $fieldsCollection = Mockery::mock();
        $fieldsCollection->shouldReceive('all')->andReturn(['body' => $field]);

        $blueprint = Mockery::mock();
        $blueprint->shouldReceive('fields')->andReturn($fieldsCollection);

        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn($id);
        $entry->shouldReceive('blueprint')->andReturn($blueprint);
        $entry->shouldReceive('get')->with('body')->andReturn($bardContent);

        return $entry;
    }

    public function test_link_coverage_unchanged_passes(): void
    {
        // Idempotent save (no edits) must not trigger the gate.
        $bard = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello world', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']],
            ]],
        ]]];

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($bard),
            $this->entryWithBard($bard),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_link_coverage_full_removal_passes(): void
    {
        // DetailUnlink / URL-Changer remove the entire linked phrase.
        // After: no link mark with that href anywhere → coverage = 0.
        // 0 is the "deliberate removal" branch — must NOT throw.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello world', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::a']],
            ]],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello world'],
        ]]];

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_link_coverage_url_upgrade_full_replace_passes(): void
    {
        // URL-Changer's primary use case: replace one href with another
        // for the SAME text span. Old href: chars=N → 0. New href:
        // 0 → N. Both transitions are at the boundary, both legitimate.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello world', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'https://old.example.com']],
            ]],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello world', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::new']],
            ]],
        ]]];

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_link_coverage_new_link_addition_passes(): void
    {
        // LinkInsert in plain text: no pre-existing link to preserve.
        // Before has no link with the new href → no constraint applies.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello world'],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'text', 'text' => 'world', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::new']],
            ]],
        ]]];

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_link_coverage_unlinking_one_of_multiple_marks_passes(): void
    {
        // Real bug 2026-05-11: source entry has three link marks to the
        // same target (e.g. "Erdnuss-Soba-Nudeln" linked 3× in different
        // paragraphs, total 48 chars). User removes ONE of them via the
        // inbound-detail modal. Per-href total drops 48 → 29. The pre-
        // 2026-05-11 char-sum check fired "this would shorten an existing
        // link without removing it" — a false positive: each surviving
        // mark is unchanged, one was cleanly removed. The per-mark
        // identity check (this test) MUST accept the save.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'A '],
            ['type' => 'text', 'text' => 'Erdnuss', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::soba']],
            ]],
            ['type' => 'text', 'text' => ' middle '],
            ['type' => 'text', 'text' => 'Erdnuss', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::soba']],
            ]],
            ['type' => 'text', 'text' => ' end '],
            ['type' => 'text', 'text' => 'Eier', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::soba']],
            ]],
            ['type' => 'text', 'text' => '.'],
        ]]];
        // Same paragraph minus the middle mark — surviving marks at same
        // offsets, same lengths. Clean per-mark removal.
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'A '],
            ['type' => 'text', 'text' => 'Erdnuss', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::soba']],
            ]],
            ['type' => 'text', 'text' => ' middle Erdnuss end '],
            ['type' => 'text', 'text' => 'Eier', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::soba']],
            ]],
            ['type' => 'text', 'text' => '.'],
        ]]];

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_link_coverage_extension_passes(): void
    {
        // Auto-Link rule running multiple times can grow the linked-char
        // count for a single href when an additional occurrence becomes
        // a match. Before=N, after=N+M is monotonic-grow → allowed.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Mention '],
            ['type' => 'text', 'text' => 'Brauner', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::brauner']],
            ]],
            ['type' => 'text', 'text' => ' once. Mention Brauner twice.'],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Mention '],
            ['type' => 'text', 'text' => 'Brauner', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::brauner']],
            ]],
            ['type' => 'text', 'text' => ' once. Mention '],
            ['type' => 'text', 'text' => 'Brauner', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::brauner']],
            ]],
            ['type' => 'text', 'text' => ' twice.'],
        ]]];

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_link_coverage_partial_destruction_throws(): void
    {
        // Bug B in minimal form. Before: ONE node "Brauner-Zucker-Speck-
        // Kekse" linked to X. After: split into "Brauner"(Y) +
        // "-Zucker-Speck-Kekse"(X). Coverage of X drops 26 → 18 — partial
        // destruction. Gate must throw and abort the save.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Brauner-Zucker-Speck-Kekse', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::cookies']],
            ]],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Brauner', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::pasta']],
            ]],
            ['type' => 'text', 'text' => '-Zucker-Speck-Kekse', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::cookies']],
            ]],
        ]]];

        $this->expectException(ContentCorruptionException::class);
        $this->expectExceptionMessageMatches('/shorten an existing link/');

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
    }

    public function test_link_coverage_throws_when_existing_link_partially_unlinked(): void
    {
        // Adjacent variant: half of the linked phrase becomes plain
        // text. Coverage of X drops, no replacement href takes over.
        // Still partial destruction, must throw.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Brauner-Zucker-Speck-Kekse', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::cookies']],
            ]],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Brauner'],
            ['type' => 'text', 'text' => '-Zucker-Speck-Kekse', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic::cookies']],
            ]],
        ]]];

        $this->expectException(ContentCorruptionException::class);

        ContentSafetyValidator::ensureLinkCoveragePreserved(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
    }

    public function test_link_coverage_skips_field_with_unsupported_type(): void
    {
        // Plain `text` / `textarea` fields are intentionally not walked
        // (Linkwise's retreat — those render as plaintext). The gate
        // must not blow up on entries whose blueprint has only such
        // fields. Mirrors the existing collectViolations pattern: get()
        // is called unconditionally per handle, then the type-switch
        // decides whether to walk.
        $field = Mockery::mock();
        $field->shouldReceive('type')->andReturn('text');
        $coll = Mockery::mock();
        $coll->shouldReceive('all')->andReturn(['headline' => $field]);
        $bp = Mockery::mock();
        $bp->shouldReceive('fields')->andReturn($coll);

        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn('plain');
        $entry->shouldReceive('blueprint')->andReturn($bp);
        $entry->shouldReceive('get')->with('headline')->andReturn('Just plain prose.');

        ContentSafetyValidator::ensureLinkCoveragePreserved($entry, $entry);
        $this->expectNotToPerformAssertions();
    }

    public function test_link_coverage_handles_missing_blueprint(): void
    {
        // Entry with no blueprint resolves to empty coverage map →
        // no comparisons → no false positives.
        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('id')->andReturn('no-bp');
        $entry->shouldReceive('blueprint')->andThrow(new \RuntimeException('no blueprint'));

        ContentSafetyValidator::ensureLinkCoveragePreserved($entry, $entry);
        $this->expectNotToPerformAssertions();
    }

    // ─── ensureNoNewAdjacentSameMarks ────────────────────────────────────
    //
    // Bug 16 fragmentation gate. Diff-mode: existing fragments don't block,
    // only NEW ones do. The migration `linkwise:normalize-bard` cleans
    // legacy data; this gate prevents new regressions.

    public function test_adjacent_pairs_unchanged_passes(): void
    {
        // Idempotent save: same fragment count in before + after → no throw.
        // Existing fragmented entries must still be saveable.
        $href = 'statamic://entry::a';
        $fragmented = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'über ', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'Erdnuss', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]];

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($fragmented),
            $this->entryWithBard($fragmented),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_adjacent_pairs_clean_to_clean_passes(): void
    {
        // No fragments anywhere — most common case, must be a fast no-op.
        $clean = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Just clean prose.'],
        ]]];

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($clean),
            $this->entryWithBard($clean),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_adjacent_pairs_introducing_new_fragment_throws(): void
    {
        // The regression we're preventing: a save that produces a NEW
        // adjacent same-mark pair (e.g. via linkAcrossNodes without
        // normalize). before = clean, after = fragmented → throw.
        $href = 'statamic://entry::a';
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Heute über Erdnuss-Soba-Nudeln nachgedacht.'],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Heute '],
            ['type' => 'text', 'text' => 'über ', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'Erdnuss-Soba-Nudeln', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => ' nachgedacht.'],
        ]]];

        $this->expectException(\Arturrossbach\Linkwise\Exceptions\ContentCorruptionException::class);
        $this->expectExceptionMessage('would introduce 1 new fragmented-link pair');

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
    }

    public function test_adjacent_pairs_cleanup_passes(): void
    {
        // Migration / normalize would do the opposite of the bug:
        // before had a pair, after doesn't. That's an improvement —
        // must not throw (negative delta is fine).
        $href = 'statamic://entry::a';
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'über ', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'Erdnuss', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'über Erdnuss', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]];

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_adjacent_pairs_counts_runs_correctly(): void
    {
        // 3 adjacent same-mark siblings = 2 pairs, 4 = 3 pairs. Walker
        // must count runs, not just isolated pairs.
        $href = 'statamic://entry::a';
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'clean'],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'text', 'text' => 'c', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]];

        $this->expectException(\Arturrossbach\Linkwise\Exceptions\ContentCorruptionException::class);
        $this->expectExceptionMessage('2 new fragmented-link pair'); // 3 same-mark siblings → 2 pairs

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
    }

    public function test_adjacent_pairs_different_hrefs_not_pair(): void
    {
        // Different href = semantically different link; not a fragment.
        // before clean, after has two different-href siblings → not a pair → no throw.
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'clean'],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://y.test']]]],
        ]]];

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_adjacent_pairs_text_separated_by_non_text_not_pair(): void
    {
        // hardBreak between same-mark texts breaks adjacency.
        $href = 'statamic://entry::a';
        $before = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'clean'],
        ]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]];

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_adjacent_pairs_counts_into_nested_content(): void
    {
        // Fragments inside list-items / blockquotes etc. count too.
        $href = 'statamic://entry::a';
        $before = [['type' => 'blockquote', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'clean']]],
        ]]];
        $after = [['type' => 'blockquote', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
                ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ]],
        ]]];

        $this->expectException(\Arturrossbach\Linkwise\Exceptions\ContentCorruptionException::class);

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
    }

    public function test_adjacent_pairs_ignores_codeblock_content(): void
    {
        // codeBlock content is opaque — fragments inside (unlikely but
        // possible) don't count. Consistent with the rest of the walkers.
        $before = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'clean']]]];
        $after = [['type' => 'codeBlock', 'content' => [
            ['type' => 'text', 'text' => 'int '],
            ['type' => 'text', 'text' => 'x;'],
        ]]];

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
        $this->expectNotToPerformAssertions();
    }

    public function test_adjacent_pairs_order_agnostic_mark_compare(): void
    {
        // [bold, link] and [link, bold] = same mark-set → counts as pair.
        // Linkwise and Statamic CP emit marks in different orders for the
        // same logical state; the check must catch the real fragment.
        $href = 'https://x.test';
        $before = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'clean']]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'A', 'marks' => [
                ['type' => 'bold'],
                ['type' => 'link', 'attrs' => ['href' => $href]],
            ]],
            ['type' => 'text', 'text' => 'B', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => $href]],
                ['type' => 'bold'],
            ]],
        ]]];

        $this->expectException(\Arturrossbach\Linkwise\Exceptions\ContentCorruptionException::class);

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
    }

    public function test_adjacent_pairs_plain_text_no_marks_counts(): void
    {
        // After unlink, mark-strip leaves adjacent plain-text siblings.
        // That counts as a fragment too — Statamic renders the same as
        // a single text node, but the structure is dirty. Walker counts it.
        $before = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Heute über Erdnuss nachgedacht.']]]];
        $after = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Heute '],
            ['type' => 'text', 'text' => 'über '],
            ['type' => 'text', 'text' => 'Erdnuss'],
            ['type' => 'text', 'text' => ' nachgedacht.'],
        ]]];

        $this->expectException(\Arturrossbach\Linkwise\Exceptions\ContentCorruptionException::class);
        // 4 adjacent plain-text nodes = 3 pairs.
        $this->expectExceptionMessage('3 new fragmented-link pair');

        ContentSafetyValidator::ensureNoNewAdjacentSameMarks(
            $this->entryWithBard($before),
            $this->entryWithBard($after),
        );
    }
}
