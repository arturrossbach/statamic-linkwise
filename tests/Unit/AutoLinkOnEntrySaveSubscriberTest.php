<?php

namespace Inkline\Linkwise\Tests\Unit;

use Inkline\Linkwise\AutoLink\AutoLinkRule;
use Inkline\Linkwise\Subscribers\AutoLinkOnEntrySaveSubscriber;
use Inkline\Linkwise\Tests\TestCase;

/**
 * Truth table for AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave().
 *
 * Inputs:
 *   - rule.active             (bool)
 *   - rule.autoApplyOnSave    ('follow_global' | 'always' | 'never')
 *   - rule.targetEntryId vs.  saved entryId (self-link guard)
 *   - rule.collections vs.    entry's collection
 *   - $globalEnabled          (master switch)
 *
 * Each cell of the matrix has its own test so a regression on any combination
 * is loud. Without these tests the cascade-suppression + tri-state logic was
 * untested in V1 — exactly the place users hit subtle bugs.
 */
class AutoLinkOnEntrySaveSubscriberTest extends TestCase
{
    private function rule(array $overrides = []): AutoLinkRule
    {
        return AutoLinkRule::create(array_merge([
            'id' => 'r-'.uniqid(),
            'keyword' => 'kw',
            'url' => 'https://example.com',
            'active' => true,
            'auto_apply_on_save' => 'follow_global',
            'collections' => [],
        ], $overrides));
    }

    // ── Tri-state × global ──

    public function test_follow_global_with_global_on_fires(): void
    {
        $rules = [$this->rule(['auto_apply_on_save' => 'follow_global'])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', true);
        $this->assertCount(1, $eligible);
    }

    public function test_follow_global_with_global_off_skips(): void
    {
        $rules = [$this->rule(['auto_apply_on_save' => 'follow_global'])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', false);
        $this->assertCount(0, $eligible);
    }

    public function test_always_with_global_on_fires(): void
    {
        $rules = [$this->rule(['auto_apply_on_save' => 'always'])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', true);
        $this->assertCount(1, $eligible);
    }

    public function test_always_with_global_off_still_fires(): void
    {
        // The whole point of 'always' — overrides global.
        $rules = [$this->rule(['auto_apply_on_save' => 'always'])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', false);
        $this->assertCount(1, $eligible);
    }

    public function test_never_with_global_on_skips(): void
    {
        $rules = [$this->rule(['auto_apply_on_save' => 'never'])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', true);
        $this->assertCount(0, $eligible);
    }

    public function test_never_with_global_off_skips(): void
    {
        $rules = [$this->rule(['auto_apply_on_save' => 'never'])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', false);
        $this->assertCount(0, $eligible);
    }

    // ── Active flag ──

    public function test_inactive_rule_skipped_even_when_eligible(): void
    {
        $rules = [$this->rule(['active' => false, 'auto_apply_on_save' => 'always'])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', true);
        $this->assertCount(0, $eligible);
    }

    // ── Self-link guard ──

    public function test_rule_targeting_saved_entry_is_skipped(): void
    {
        $rules = [$this->rule([
            'url' => 'statamic://entry::entry-1',
            'auto_apply_on_save' => 'always',
        ])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', true);
        $this->assertCount(0, $eligible);
    }

    public function test_rule_targeting_other_entry_fires(): void
    {
        $rules = [$this->rule([
            'url' => 'statamic://entry::entry-OTHER',
            'auto_apply_on_save' => 'always',
        ])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', true);
        $this->assertCount(1, $eligible);
    }

    // ── Per-rule collection restriction (the bug we just fixed) ──

    public function test_rule_with_collection_restriction_skips_other_collection(): void
    {
        $rules = [$this->rule([
            'collections' => ['blog'],
            'auto_apply_on_save' => 'always',
        ])];
        // Entry is in 'news' but rule restricts to 'blog' — must skip.
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'news', true);
        $this->assertCount(0, $eligible);
    }

    public function test_rule_with_collection_restriction_fires_in_allowed_collection(): void
    {
        $rules = [$this->rule([
            'collections' => ['blog', 'docs'],
            'auto_apply_on_save' => 'always',
        ])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'docs', true);
        $this->assertCount(1, $eligible);
    }

    public function test_rule_with_no_collection_restriction_fires_in_any_collection(): void
    {
        $rules = [$this->rule([
            'collections' => [],
            'auto_apply_on_save' => 'always',
        ])];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'whatever', true);
        $this->assertCount(1, $eligible);
    }

    // ── Multi-rule mix ──

    public function test_only_eligible_rules_returned_in_mixed_set(): void
    {
        $rules = [
            $this->rule(['keyword' => 'A', 'auto_apply_on_save' => 'always']),     // fires
            $this->rule(['keyword' => 'B', 'auto_apply_on_save' => 'never']),       // skip
            $this->rule(['keyword' => 'C', 'active' => false]),                     // skip
            $this->rule(['keyword' => 'D', 'auto_apply_on_save' => 'follow_global']), // skip when global=false
            $this->rule(['keyword' => 'E', 'collections' => ['other']]),            // skip wrong collection
        ];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', false);

        $this->assertCount(1, $eligible);
        $this->assertSame('A', $eligible[0]->keyword);
    }

    public function test_returns_indexed_array_not_keyed(): void
    {
        // array_filter preserves keys — we wrap with array_values so callers
        // can foreach safely without surprises.
        $rules = [
            'k1' => $this->rule(['keyword' => 'A', 'active' => false]), // dropped
            'k2' => $this->rule(['keyword' => 'B', 'auto_apply_on_save' => 'always']),
        ];
        $eligible = AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave($rules, 'entry-1', 'blog', false);
        $this->assertSame([0], array_keys($eligible));
    }

    // ── Empty corpus ──

    public function test_no_rules_returns_empty(): void
    {
        $this->assertSame([], AutoLinkOnEntrySaveSubscriber::eligibleRulesForSave([], 'entry-1', 'blog', true));
    }
}
