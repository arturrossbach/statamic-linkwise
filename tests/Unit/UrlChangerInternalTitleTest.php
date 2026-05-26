<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\Tests\TestCase;
use Arturrossbach\Linkwise\UrlChanger\UrlMatcher;

/**
 * V1.2 PR-I pins for the URL Changer's internal-href surfacing.
 *
 * Two bug-classes from the 2026-05-26 user-smoke:
 *   1. Bare UUID search input — user pastes `65c88ed2-...` (without the
 *      `statamic://entry::` prefix) and smart-match silently fails.
 *      Expected: smart-match treats a bare UUID as shorthand for the
 *      canonical internal-ref form.
 *   2. (Display fix lives in UrlReplacer::processEntry — covered by
 *      integration test against a real entry; not unit-testable in
 *      isolation because it relies on Entry::find resolution. Pin
 *      below sanity-checks the matcher only.)
 *
 * Out of scope for V1.2: title-based search-input. That's a different
 * input mode (entry-picker autocomplete) tracked for V1.3.
 */
class UrlChangerInternalTitleTest extends TestCase
{
    public function test_smart_match_resolves_bare_uuid_to_internal_href(): void
    {
        // User pastes JUST the UUID into the search box. Smart-match
        // should treat it as shorthand for `statamic://entry::<uuid>` and
        // match the canonical internal-ref form.
        $matcher = new UrlMatcher();
        $matcher->setMode('smart');

        $uuid = '65c88ed2-40c0-4fb5-aa26-34ae2cca0936';
        $href = 'statamic://entry::'.$uuid;

        $this->assertTrue($matcher->hrefMatches($href, $uuid), 'Bare UUID must match its canonical statamic:// href in smart mode.');
    }

    public function test_smart_match_trims_whitespace_around_uuid(): void
    {
        // Copy-paste often pulls leading/trailing whitespace. Matching the
        // canonical href would otherwise fail because of a stray space.
        $matcher = new UrlMatcher();
        $matcher->setMode('smart');

        $uuid = '65c88ed2-40c0-4fb5-aa26-34ae2cca0936';
        $href = 'statamic://entry::'.$uuid;

        $this->assertTrue($matcher->hrefMatches($href, '  '.$uuid.' '), 'UUID with stray whitespace must still match.');
    }

    public function test_smart_match_full_statamic_form_still_matches_exactly(): void
    {
        // Pre-V1.2 behavior must survive: pasting the full
        // `statamic://entry::<uuid>` form still matches the same href.
        $matcher = new UrlMatcher();
        $matcher->setMode('smart');

        $uuid = '65c88ed2-40c0-4fb5-aa26-34ae2cca0936';
        $href = 'statamic://entry::'.$uuid;

        $this->assertTrue($matcher->hrefMatches($href, $href));
    }

    public function test_smart_match_rejects_uuid_against_other_internal_href(): void
    {
        // Bare-UUID shorthand must only match the SAME UUID's href, not
        // any internal href. Smart-match doesn't get to be permissive.
        $matcher = new UrlMatcher();
        $matcher->setMode('smart');

        $href = 'statamic://entry::65c88ed2-40c0-4fb5-aa26-34ae2cca0936';
        $otherUuid = '11111111-1111-1111-1111-111111111111';

        $this->assertFalse($matcher->hrefMatches($href, $otherUuid));
    }

    public function test_smart_match_rejects_non_uuid_against_internal_href(): void
    {
        // A normal word in the search box must NOT collide with an internal
        // href. Smart-match's substring fallback is for HTTP URLs only.
        $matcher = new UrlMatcher();
        $matcher->setMode('smart');

        $href = 'statamic://entry::65c88ed2-40c0-4fb5-aa26-34ae2cca0936';

        $this->assertFalse($matcher->hrefMatches($href, 'datenbank'));
        $this->assertFalse($matcher->hrefMatches($href, '65c88ed2'), 'Partial UUID must not match — would surface ambiguous results.');
    }

    public function test_exact_mode_still_requires_full_href(): void
    {
        // Exact mode is documented as literal string compare. Bare UUID
        // shorthand should NOT bleed into exact mode — would change the
        // contract of "exact".
        $matcher = new UrlMatcher();
        $matcher->setMode('exact');

        $href = 'statamic://entry::65c88ed2-40c0-4fb5-aa26-34ae2cca0936';
        $uuid = '65c88ed2-40c0-4fb5-aa26-34ae2cca0936';

        $this->assertFalse($matcher->hrefMatches($href, $uuid), 'Exact mode must reject bare UUID — it expects the full href.');
        $this->assertTrue($matcher->hrefMatches($href, $href));
    }
}
