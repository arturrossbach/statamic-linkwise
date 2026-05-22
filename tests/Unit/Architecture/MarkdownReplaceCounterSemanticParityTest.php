<?php

namespace Arturrossbach\Linkwise\Tests\Unit\Architecture;

use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Structural pin for [[architectural_health]] Klasse 4.x
 * (Sister-Argument-Parität between replaceNth* families).
 *
 * ## Why this test exists
 *
 * Bug 2026-05-22 (Cloudways smoke): `UrlReplacerWithPosition::replaceNthInMarkdown`
 * accepted `(string $markdown, string $oldUrl, ...)` while its Bard/Replicator
 * siblings accepted `(string $bardContent, string $search, string $oldUrl, ...)`.
 * The missing `$search` argument forced the Markdown implementation into a
 * preg_quote($oldUrl)-restricted regex, which counted occurrences only within
 * matches of that exact URL — but `UrlMatcher::findInMarkdown` counted
 * globally (one ++ per hrefMatches positive).
 *
 * Counter-semantic drift made every non-first match in a multi-URL Markdown
 * field silently un-replaceable: user clicked Apply, the activity-log toast
 * said "Links were already gone" even though the link was right there.
 *
 * ## What this test pins
 *
 * Method-signature symmetry: replaceNthIn{Bard,Replicator,Markdown} all
 * carry `$search` as the 2nd positional argument. Future refactors can't
 * drop it from Markdown again without making this test red.
 *
 * Callsite symmetry: `UrlReplacer::applySelected` (the only frontend-driven
 * caller) MUST forward `$effectiveSearch` to all three families.
 *
 * Linked memo: [[session_2026_05_22_cloudways_smoke_handoff]],
 * [[feedback_proactive_sister_bug_search]].
 */
class MarkdownReplaceCounterSemanticParityTest extends TestCase
{
    public function test_signatures_share_search_parameter_at_position_2(): void
    {
        $positionSrc = file_get_contents(dirname(__DIR__, 3).'/src/UrlChanger/UrlReplacerWithPosition.php');

        // Bard / Replicator / Markdown — same first two positional args:
        // ($content, string $search, ...). The pin asserts each signature
        // explicitly so a refactor that flips arg order in one family
        // makes the test fail loudly instead of producing silent drift.
        $this->assertMatchesRegularExpression(
            '/public function replaceNthInBard\(\s*array \$bardContent\s*,\s*string \$search\s*,/s',
            $positionSrc,
            'replaceNthInBard must carry $search as 2nd positional arg.',
        );
        $this->assertMatchesRegularExpression(
            '/public function replaceNthInReplicator\(\s*array \$sets\s*,\s*string \$search\s*,/s',
            $positionSrc,
            'replaceNthInReplicator must carry $search as 2nd positional arg.',
        );
        $this->assertMatchesRegularExpression(
            '/public function replaceNthInMarkdown\(\s*string \$markdown\s*,\s*string \$search\s*,/s',
            $positionSrc,
            'replaceNthInMarkdown must carry $search as 2nd positional arg '
            .'(sister-argument-parity with Bard/Replicator). Pre-2026-05-22 the '
            .'arg was missing → counter-semantic drift broke multi-URL replace.',
        );
    }

    public function test_delegation_layer_forwards_search_to_all_three_families(): void
    {
        $delegateSrc = file_get_contents(dirname(__DIR__, 3).'/src/UrlChanger/UrlReplacer.php');

        // The public delegation methods in UrlReplacer mirror the position-
        // replacer signatures. Without the same 2nd-arg shape, calling
        // UrlReplacer::replaceNthInMarkdown would silently drop $search.
        $this->assertMatchesRegularExpression(
            '/public function replaceNthInMarkdown\(\s*string \$markdown\s*,\s*string \$search\s*,/s',
            $delegateSrc,
            'UrlReplacer::replaceNthInMarkdown (delegation) must accept $search '
            .'as 2nd arg so callers can drive smart-mode semantics.',
        );
    }

    public function test_applySelected_passes_effectiveSearch_to_markdown_branch(): void
    {
        // Smoking-gun callsite: applySelected loops over per-replacement
        // payloads, has $effectiveSearch in scope, and forwards it to the
        // bard / replicator branches. Pre-fix the Markdown branch
        // omitted $effectiveSearch — pin so the regression is structural.
        $src = file_get_contents(dirname(__DIR__, 3).'/src/UrlChanger/UrlReplacer.php');

        $this->assertMatchesRegularExpression(
            '/\$this->replaceNthInMarkdown\(\s*\$value\s*,\s*\$effectiveSearch\s*,/',
            $src,
            'UrlReplacer::applySelected MUST forward $effectiveSearch to '
            .'replaceNthInMarkdown — same shape as the existing Bard + Replicator '
            .'calls a few lines above. Forgetting it re-introduces user-bug 2026-05-22.',
        );
    }

    public function test_counter_uses_hrefMatches_not_url_restricted_regex(): void
    {
        // The fix replaces `preg_quote($oldUrl)` inside the regex with a
        // global `\([^)]+\)` capture plus a hrefMatches() filter — same
        // semantic Bard/Replicator already use. If a future refactor
        // re-introduces the URL-restricted regex, the bug returns and
        // this pin catches it.
        $src = file_get_contents(dirname(__DIR__, 3).'/src/UrlChanger/UrlReplacerWithPosition.php');

        // Extract the body of replaceNthInMarkdown for targeted matching.
        if (! preg_match(
            '/public function replaceNthInMarkdown\b.*?\n    \}/s',
            $src,
            $body,
        )) {
            $this->fail('Unable to locate replaceNthInMarkdown body for pin check.');
        }
        $methodBody = $body[0];

        $this->assertStringNotContainsString(
            'preg_quote($oldUrl',
            $methodBody,
            'replaceNthInMarkdown must NOT re-introduce preg_quote($oldUrl) '
            .'inside its regex — that drives the URL-restricted counter '
            .'semantic that broke user-bug 2026-05-22.',
        );
        $this->assertStringContainsString(
            'hrefMatches($href, $search)',
            $methodBody,
            'replaceNthInMarkdown must filter via hrefMatches($href, $search) '
            .'so its counter aligns with findInMarkdown and replaceNthInBard.',
        );
    }
}
