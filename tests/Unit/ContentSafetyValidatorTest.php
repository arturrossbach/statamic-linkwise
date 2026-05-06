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
    /** Helper: invoke private validateMarkdown directly with a fake entry-id/field. */
    private function validateMd(string $markdown): void
    {
        $m = new ReflectionMethod(ContentSafetyValidator::class, 'validateMarkdown');
        $m->setAccessible(true);
        $m->invoke(null, 'test-entry', 'body', $markdown);
    }

    /** Helper: invoke private validateBardTree directly. */
    private function validateBard(array $content): void
    {
        $m = new ReflectionMethod(ContentSafetyValidator::class, 'validateBardTree');
        $m->setAccessible(true);
        $m->invoke(null, 'test-entry', 'body', $content);
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
}
