<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Exceptions\ContentCorruptionException;
use Arturrossbach\Linkwise\Support\ContentSafetyValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
}
